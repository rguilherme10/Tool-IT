using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Execution.Handlers;
using AgentToolit.Core.Net;
using AgentToolit.Core.Transfers;
using AgentToolit.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit.Abstractions;

namespace AgentToolit.Tests;

/// <summary>
/// Handlers file.send / file.fetch, Throttle, raízes com ACL restrita, catálogo/validador e o TaskRunner com contexto.
/// Só diretórios temporários criados pelo teste (raiz "Inbox" e pasta "Secret" fora dela); links removidos sem recursão.
/// O Manager é um <see cref="FakeTransferApi"/> em memória.
/// </summary>
public class FileHandlersTests(ITestOutputHelper output)
{
    internal const string EndpointId = "8a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d";
    internal const string TransferId = "3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21";

    private static string Hex(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();

    private static JsonElement Params(string id = TransferId) => TaskExecutionTests.P("{\"transfer_id\":\"" + id + "\"}");

    /// <summary>Raiz temporária + settings + runtime registrado + Manager falso.</summary>
    internal sealed class Fixture : IDisposable
    {
        public TempDir Dir { get; } = new();
        public string Root { get; }
        public string Outside { get; }
        public AgentSettings Settings { get; }
        public AgentRuntime Runtime { get; }
        public FakeTransferApi Api { get; } = new();
        public FakeDiskSpace Disk { get; } = new();
        public List<int> Progress { get; } = [];

        public Fixture()
        {
            (Root, Outside) = PathGuardTests.Layout(Dir);
            Settings = new AgentSettings { TransferRoots = [Root], TransferFetchOnlyRoots = [] };
            Runtime = new AgentRuntime(new AgentStateStore(Dir.Paths()));
            Runtime.SetEnrolled(new AgentState { AgentId = "11111111-2222-4333-8444-555555555555", EndpointId = EndpointId, EnrolledAt = DateTimeOffset.UtcNow });
        }

        public FileSendHandler Send() =>
            new(Api, Settings, TimeProvider.System, Runtime, Disk, NullLogger<FileSendHandler>.Instance);

        public FileFetchHandler Fetch() =>
            new(Api, Settings, TimeProvider.System, Runtime, NullLogger<FileFetchHandler>.Instance);

        public TaskContext Context() => new("6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80", "0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10", (p, _) =>
        {
            lock (Progress)
            {
                Progress.Add(p);
            }

            return Task.CompletedTask;
        });

        public Task<TaskOutcome> RunSend(CancellationToken ct = default) => Send().ExecuteAsync(Params(), Context(), ct);

        public Task<TaskOutcome> RunFetch(CancellationToken ct = default) => Fetch().ExecuteAsync(Params(), Context(), ct);

        /// <summary>Envio de <paramref name="content"/> para <paramref name="remotePath"/> (chunk pequeno: vários chunks).</summary>
        public void SetupSend(string remotePath, byte[] content, int chunkSize = 4, bool overwrite = false, string? sha = null)
        {
            Api.Content = content;
            Api.Info = new TransferInfo(TransferId, EndpointId, TransferProtocol.Send, content.Length, sha ?? Hex(content), chunkSize,
                (content.Length + chunkSize - 1) / chunkSize, remotePath, overwrite, []);
        }

        public void SetupFetch(string remotePath, int chunkSize = 4, long? size = null, IReadOnlyList<int>? received = null) =>
            Api.Info = new TransferInfo(TransferId, EndpointId, TransferProtocol.Fetch, size, null, chunkSize,
                size is null ? null : (int)((size + chunkSize - 1) / chunkSize), remotePath, false, received ?? []);

        public static string PartPath(string dir) => Path.Combine(dir, ".toolit-" + TransferId + ".part");

        public void Dispose() => Dir.Dispose();
    }

    /// <summary>Manager em memória: registra os pedidos de chunk, os PUTs, o begin e o complete.</summary>
    internal sealed class FakeTransferApi : ITransferApi
    {
        public TransferInfo? Info;
        public byte[] Content = [];
        public int GetCalls;
        public readonly List<int> ChunkRequests = [];
        public readonly Dictionary<int, byte[]> Put = [];
        public readonly Dictionary<int, string> PutSha = [];
        public readonly List<long> Begins = [];
        public readonly List<(string Sha, long Size)> Completes = [];
        public Func<int, (byte[] Bytes, string Sha)?>? ChunkOverride;
        public Action<int>? OnChunk;
        public Func<int, Exception?>? ChunkError;
        public Exception? BeginError;
        public string CompleteStatus = "completed";

        public Task<TransferInfo> GetAsync(string id, CancellationToken ct)
        {
            Interlocked.Increment(ref GetCalls);

            return Task.FromResult(Info ?? throw new ManagerRejectedException(404, "request.not_found", "x"));
        }

        public Task<(byte[] Bytes, string Sha256)> GetChunkAsync(string id, int n, CancellationToken ct)
        {
            lock (ChunkRequests)
            {
                ChunkRequests.Add(n);
            }
            if (ChunkError?.Invoke(n) is { } error)
            {
                throw error;
            }
            OnChunk?.Invoke(n);
            if (ChunkOverride?.Invoke(n) is { } custom)
            {
                return Task.FromResult(custom);
            }

            var size = Info!.ChunkSize;
            var bytes = Content.Skip(n * size).Take(size).ToArray();

            return Task.FromResult((bytes, Hex(bytes)));
        }

        public Task PutChunkAsync(string id, int n, byte[] bytes, string sha256, CancellationToken ct)
        {
            if (ChunkError?.Invoke(n) is { } error)
            {
                throw error;
            }
            Put[n] = bytes.ToArray();
            PutSha[n] = sha256;
            OnChunk?.Invoke(n);

            return Task.CompletedTask;
        }

        public Task BeginAsync(string id, long size, CancellationToken ct)
        {
            if (BeginError is not null)
            {
                throw BeginError;
            }
            Begins.Add(size);

            return Task.CompletedTask;
        }

        public Task<string> CompleteAsync(string id, string sha256, long size, CancellationToken ct)
        {
            Completes.Add((sha256, size));

            return Task.FromResult(CompleteStatus);
        }
    }

    internal sealed class FakeDiskSpace : IDiskSpace
    {
        public long? Free = 10L * 1024 * 1024 * 1024;

        public long? AvailableFreeSpace(string directory) => Free;
    }

    private static byte[] Bytes(string text) => Encoding.UTF8.GetBytes(text);

    private static void AssertEmpty(string dir) => Assert.Empty(Directory.EnumerateFileSystemEntries(dir));

    // ---------------------------------------------------------------- file.send

    [Fact]
    public async Task Send_happy_path_downloads_all_chunks_verifies_and_moves()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "relatorio.pdf");
        var content = Bytes("0123456789");   // 3 chunks de 4 (4+4+2)
        f.SetupSend(final, content);

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal(content, File.ReadAllBytes(final));
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.Equal([0, 1, 2], f.Api.ChunkRequests);
        Assert.Equal([(Hex(content), 10L)], f.Api.Completes);   // uma vez, antes de devolver
        Assert.NotEmpty(f.Progress);
        Assert.All(f.Progress, p => Assert.InRange(p, 0, 99));
        Assert.Contains("relatorio.pdf", outcome.Stdout);
        Assert.Contains(Hex(content), outcome.Stdout);
    }

    [Fact]
    public async Task Send_of_an_empty_file_creates_it()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "vazio.txt");
        f.SetupSend(final, []);

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Empty(File.ReadAllBytes(final));
        Assert.Empty(f.Api.ChunkRequests);
    }

    [Fact]
    public async Task Send_resumes_from_an_existing_part()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        var content = Bytes("0123456789");
        f.SetupSend(final, content);
        File.WriteAllBytes(Fixture.PartPath(f.Root), Bytes("0123"));   // 1 chunk já baixado

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal([1, 2], f.Api.ChunkRequests);
        Assert.Equal(content, File.ReadAllBytes(final));
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
    }

    [Fact]
    public async Task Send_part_with_invalid_length_is_discarded_and_restarted()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        var content = Bytes("0123456789");
        f.SetupSend(final, content);
        File.WriteAllBytes(Fixture.PartPath(f.Root), Bytes("012"));   // não é múltiplo do chunk

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal([0, 1, 2], f.Api.ChunkRequests);
        Assert.Equal(content, File.ReadAllBytes(final));
    }

    [Fact]
    public async Task Send_chunk_with_wrong_hash_fails_and_removes_the_part()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"));
        f.Api.ChunkOverride = n => n == 1 ? (Bytes("4567"), Hex(Bytes("xxxx"))) : null;

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        Assert.Contains("chunk 1", outcome.Error);   // pego no chunk, não só no hash total
        Assert.Equal([0, 1], f.Api.ChunkRequests);
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.False(File.Exists(final));
        Assert.Empty(f.Api.Completes);
    }

    [Fact]
    public async Task Send_over_a_destination_that_is_a_hard_link_is_refused_even_with_overwrite()
    {
        using var f = new Fixture();
        var secret = Path.Combine(f.Outside, "segredo.txt");
        File.WriteAllBytes(secret, Bytes("segredo"));
        var final = Path.Combine(f.Root, "a.txt");
        Assert.True(CreateHardLinkW(final, secret, IntPtr.Zero),
            "Ambiente não permitiu criar hard link (CreateHardLinkW); o teste NÃO foi executado. Erro Win32 " + Marshal.GetLastWin32Error());
        f.SetupSend(final, Bytes("0123456789"), overwrite: true);

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Equal(Bytes("segredo"), File.ReadAllBytes(secret));
        Assert.Empty(f.Api.ChunkRequests);
        File.Delete(final);   // só o link
    }

    [Fact]
    public async Task Send_chunk_with_wrong_length_fails_and_removes_the_part()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"));
        f.Api.ChunkOverride = n => n == 0 ? (Bytes("012"), Hex(Bytes("012"))) : null;

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.Equal([0], f.Api.ChunkRequests);
    }

    [Fact]
    public async Task Send_whole_file_hash_mismatch_fails_and_removes_the_part()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"), sha: new string('a', 64));

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        Assert.Contains("hash", outcome.Error);
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.False(File.Exists(final));
        Assert.Empty(f.Api.Completes);
    }

    [Fact]
    public async Task Send_destination_with_same_hash_completes_without_downloading()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        var content = Bytes("0123456789");
        File.WriteAllBytes(final, content);
        f.SetupSend(final, content);

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Empty(f.Api.ChunkRequests);
        Assert.Equal([(Hex(content), 10L)], f.Api.Completes);
    }

    [Fact]
    public async Task Send_destination_with_different_hash_without_overwrite_is_rejected()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        File.WriteAllBytes(final, Bytes("antigo"));
        f.SetupSend(final, Bytes("0123456789"));

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Contains("já existe", outcome.Error);
        Assert.Equal(Bytes("antigo"), File.ReadAllBytes(final));
        Assert.Empty(f.Api.ChunkRequests);
    }

    [Fact]
    public async Task Send_with_overwrite_replaces_the_destination()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        File.WriteAllBytes(final, Bytes("antigo"));
        var content = Bytes("0123456789");
        f.SetupSend(final, content, overwrite: true);

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal(content, File.ReadAllBytes(final));
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
    }

    [Fact]
    public async Task Send_to_a_directory_outside_the_roots_is_rejected()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Outside, "a.txt"), Bytes("0123"));

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        AssertEmpty(f.Outside);
        Assert.Empty(f.Api.ChunkRequests);
    }

    [Fact]
    public async Task Send_into_a_fetch_only_root_is_rejected()
    {
        using var f = new Fixture();
        f.Settings.TransferFetchOnlyRoots = [f.Outside];
        f.SetupSend(Path.Combine(f.Outside, "a.txt"), Bytes("0123"));

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        AssertEmpty(f.Outside);
    }

    /// <summary>Caminhos hostis: inline + nomes inválidos e extensões bloqueadas do vetor compartilhado.</summary>
    private static List<string> HostilePaths(string root)
    {
        var list = new List<string>
        {
            root + @"\..\Secret\a.txt",
            root + @"\a.txt:fluxo",
            root + @"\CON.txt",
            root + @"\a.exe",
            root + @"\relatorio.pdf.exe",
            root + @"\a.EXE",
            root + @"\a.exe.",
            root + @"\a.exe ",
            root + @"\a~1.txt",
            root + "/a.txt",
            root + "\\a\u202Etxt.pdf",
            root + "\\a\u200Bb.txt",
            root + @"\",
            @"\\servidor\pasta\a.txt",
            @"\\?\" + root + @"\a.txt",
            "a.txt",
            root + "\\" + new string('a', Math.Max(1, 261 - root.Length - 1)),
        };

        var vector = PathGuardTestsVector();
        foreach (var c in vector.GetProperty("files").EnumerateArray())
        {
            var name = c.GetProperty("name").GetString()!;
            if (!c.GetProperty("ok").GetBoolean() && !name.Contains('\\'))
            {
                list.Add(root + "\\" + name);
            }
        }
        foreach (var c in vector.GetProperty("blocked_extensions").EnumerateArray())
        {
            var name = c.GetProperty("name").GetString()!;
            if (c.GetProperty("blocked").GetBoolean() && !name.Contains('\\'))
            {
                list.Add(root + "\\" + name);
            }
        }

        return list;
    }

    private static JsonElement PathGuardTestsVector()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null && !File.Exists(Path.Combine(dir.FullName, "tests", "contracts", "path-guard-cases.json")))
        {
            dir = dir.Parent;
        }
        Assert.NotNull(dir);

        return JsonDocument.Parse(File.ReadAllText(Path.Combine(dir!.FullName, "tests", "contracts", "path-guard-cases.json"))).RootElement;
    }

    [Fact]
    public async Task Send_with_hostile_remote_paths_is_rejected_and_creates_nothing()
    {
        using var f = new Fixture();
        var paths = HostilePaths(f.Root);
        Assert.True(paths.Count > 40, "o vetor compartilhado deveria contribuir com casos");
        var failures = new List<string>();

        foreach (var path in paths)
        {
            f.SetupSend(path, Bytes("0123"));
            var outcome = await f.RunSend();
            if (outcome.Status != TaskResultStatus.Rejected)
            {
                failures.Add(JsonSerializer.Serialize(path) + " => " + outcome.Status + " " + outcome.Error);
            }
        }

        Assert.True(failures.Count == 0, string.Join("\n", failures));
        AssertEmpty(f.Root);
        AssertEmpty(f.Outside);
        Assert.Empty(f.Api.ChunkRequests);
        Assert.Empty(f.Api.Completes);
    }

    [Fact]
    public async Task Send_with_wrong_endpoint_or_direction_is_rejected()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123"));
        var good = f.Api.Info!;

        f.Api.Info = good with { EndpointId = "00000000-0000-4000-8000-000000000000" };
        Assert.Equal(TaskResultStatus.Rejected, (await f.RunSend()).Status);

        f.Api.Info = good with { Direction = TransferProtocol.Fetch };
        Assert.Equal(TaskResultStatus.Rejected, (await f.RunSend()).Status);

        f.Api.Info = good with { TransferId = "00000000-0000-4000-8000-000000000000" };
        Assert.Equal(TaskResultStatus.Rejected, (await f.RunSend()).Status);

        Assert.Empty(f.Api.ChunkRequests);
        AssertEmpty(f.Root);
    }

    [Fact]
    public async Task Send_through_a_junction_inside_the_root_is_rejected_and_outside_stays_empty()
    {
        using var f = new Fixture();
        var sub = Path.Combine(f.Root, "sub");
        var kind = PathGuardTests.CreateDirectoryLink(sub, f.Outside);
        output.WriteLine("link criado: " + kind);
        try
        {
            f.SetupSend(Path.Combine(sub, "a.txt"), Bytes("0123456789"));

            var outcome = await f.RunSend();

            Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
            Assert.Empty(f.Api.ChunkRequests);
        }
        finally
        {
            Directory.Delete(sub);   // só o link, sem recursão
        }

        AssertEmpty(f.Outside);
    }

    [Fact]
    public async Task Send_refuses_a_hard_link_planted_as_the_part_during_open()
    {
        using var f = new Fixture();
        var secret = Path.Combine(f.Outside, "segredo.txt");
        File.WriteAllBytes(secret, Bytes("SEGR"));
        var part = Fixture.PartPath(f.Root);
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123456789"));

        var planted = false;
        PathGuard.BeforeOpenForTests.Value = path =>
        {
            if (!planted && path == part)
            {
                Assert.True(CreateHardLinkW(part, secret, IntPtr.Zero),
                    "Ambiente não permitiu criar hard link (CreateHardLinkW); o teste NÃO foi executado. Erro Win32 " + Marshal.GetLastWin32Error());
                planted = true;
            }
        };
        TaskOutcome outcome;
        try
        {
            outcome = await f.RunSend();
        }
        finally
        {
            PathGuard.BeforeOpenForTests.Value = null;
        }

        Assert.True(planted);
        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Equal(Bytes("SEGR"), File.ReadAllBytes(secret));   // nada escrito no arquivo de fora
        Assert.Empty(f.Api.ChunkRequests);
        if (File.Exists(part))
        {
            File.Delete(part);   // só o link
        }
    }

    [Fact]
    public async Task Send_without_unicode_normalization_is_rejected()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123"));

        PathGuard.OverrideNormalizationForTests(false);
        TaskOutcome send, fetch;
        try
        {
            send = await f.RunSend();
            f.SetupFetch(Path.Combine(f.Root, "a.txt"));
            fetch = await f.RunFetch();
        }
        finally
        {
            PathGuard.OverrideNormalizationForTests(null);
        }

        Assert.Equal(TaskResultStatus.Rejected, send.Status);
        Assert.Equal(TaskResultStatus.Rejected, fetch.Status);
        Assert.Contains("Unicode", send.Error);
        Assert.Equal(0, f.Api.GetCalls);
    }

    [Fact]
    public async Task Transfers_with_no_usable_root_are_rejected()
    {
        using var f = new Fixture();
        f.Settings.TransferRoots = ["relativo\\pasta", @"\\servidor\share"];
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123"));

        var send = await f.RunSend();
        f.SetupFetch(Path.Combine(f.Root, "a.txt"));
        var fetch = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Rejected, send.Status);
        Assert.Contains("desligadas", send.Error);
        Assert.Equal(TaskResultStatus.Rejected, fetch.Status);
        Assert.Equal(0, f.Api.GetCalls);
    }

    [Fact]
    public async Task Send_with_insufficient_disk_space_fails_and_creates_nothing()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123456789"));
        f.Disk.Free = 1024;   // menos que tamanho + 16 MiB de folga

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        Assert.Contains("espaço insuficiente", outcome.Error);
        AssertEmpty(f.Root);
        Assert.Empty(f.Api.ChunkRequests);
    }

    [Fact]
    public async Task Send_into_a_missing_subdirectory_fails_without_creating_it()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Root, "nao-existe", "a.txt"), Bytes("0123"));

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        AssertEmpty(f.Root);
    }

    [Fact]
    public async Task Send_cancelled_mid_transfer_deletes_the_part()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"));
        using var cts = new CancellationTokenSource();
        f.Api.OnChunk = n =>
        {
            if (n == 1)
            {
                cts.Cancel();
            }
        };

        var outcome = await f.RunSend(cts.Token);

        Assert.Equal(TaskResultStatus.Cancelled, outcome.Status);
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.False(File.Exists(final));
        Assert.DoesNotContain(2, f.Api.ChunkRequests);
        Assert.Empty(f.Api.Completes);
    }

    [Fact]
    public async Task Send_conflict_from_the_manager_cancels_and_stops_writing()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"));
        f.Api.ChunkError = n => n == 1 ? new ManagerRejectedException(409, "transfer.conflict", "x") : null;

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Cancelled, outcome.Status);
        Assert.Equal([0, 1], f.Api.ChunkRequests);
        Assert.False(File.Exists(Fixture.PartPath(f.Root)));
        Assert.False(File.Exists(final));
        Assert.Empty(f.Api.Completes);
    }

    [Fact]
    public async Task Send_network_error_propagates_and_keeps_the_part_for_resume()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123456789"));
        f.Api.ChunkError = n => n == 2 ? new ManagerUnavailableException("rede") : null;

        await Assert.ThrowsAsync<ManagerUnavailableException>(() => f.RunSend());

        Assert.Equal(8, new FileInfo(Fixture.PartPath(f.Root)).Length);   // 2 chunks prontos para a próxima tentativa
    }

    [Fact]
    public async Task Send_failed_status_on_complete_is_failed()
    {
        using var f = new Fixture();
        f.SetupSend(Path.Combine(f.Root, "a.txt"), Bytes("0123456789"));
        f.Api.CompleteStatus = "failed";

        var outcome = await f.RunSend();

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
    }

    [Fact]
    public async Task Send_without_context_still_works()
    {
        using var f = new Fixture();
        var final = Path.Combine(f.Root, "a.txt");
        f.SetupSend(final, Bytes("0123456789"));

        var outcome = await f.Send().ExecuteAsync(Params(), default);

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.True(File.Exists(final));
    }

    // ---------------------------------------------------------------- file.fetch

    [Fact]
    public async Task Fetch_happy_path_sends_only_missing_chunks_and_hashes_everything()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        var content = Bytes("0123456789");
        File.WriteAllBytes(file, content);
        f.SetupFetch(file, size: 10, received: [1]);

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal([10L], f.Api.Begins);
        Assert.Equal([0, 2], f.Api.Put.Keys.Order());
        Assert.Equal(Bytes("0123"), f.Api.Put[0]);
        Assert.Equal(Bytes("89"), f.Api.Put[2]);
        Assert.Equal(Hex(Bytes("89")), f.Api.PutSha[2]);
        Assert.Equal([(Hex(content), 10L)], f.Api.Completes);
        Assert.NotEmpty(f.Progress);
        Assert.Equal(content, File.ReadAllBytes(file));   // origem intacta
    }

    [Fact]
    public async Task Fetch_with_a_different_previous_size_sends_every_chunk()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123456789"));
        f.SetupFetch(file, size: 12, received: [0, 1]);

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal([0, 1, 2], f.Api.Put.Keys.Order());
    }

    [Fact]
    public async Task Fetch_from_a_fetch_only_root_is_allowed()
    {
        using var f = new Fixture();
        var logs = Path.Combine(f.Dir.Path, "Logs");
        Directory.CreateDirectory(logs);
        f.Settings.TransferFetchOnlyRoots = [logs];
        var file = Path.Combine(logs, "agent.log");
        File.WriteAllBytes(file, Bytes("abc"));
        f.SetupFetch(file);

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Completed, outcome.Status);
        Assert.Equal(Bytes("abc"), f.Api.Put[0]);
    }

    [Fact]
    public async Task Fetch_of_a_locked_file_fails_as_in_use()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123"));
        f.SetupFetch(file);

        TaskOutcome outcome;
        using (new FileStream(file, FileMode.Open, FileAccess.ReadWrite, FileShare.None))
        {
            outcome = await f.RunFetch();
        }

        Assert.Equal(TaskResultStatus.Failed, outcome.Status);
        Assert.Contains("arquivo em uso", outcome.Error);
        Assert.Empty(f.Api.Begins);
    }

    [Fact]
    public async Task Fetch_outside_the_roots_is_rejected()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Outside, "segredo.txt");
        File.WriteAllBytes(file, Bytes("segredo"));
        f.SetupFetch(file);

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Empty(f.Api.Begins);
        Assert.Empty(f.Api.Put);
    }

    [Fact]
    public async Task Fetch_through_a_junction_or_hostile_path_is_rejected()
    {
        using var f = new Fixture();
        File.WriteAllBytes(Path.Combine(f.Outside, "a.txt"), Bytes("segredo"));
        var sub = Path.Combine(f.Root, "sub");
        PathGuardTests.CreateDirectoryLink(sub, f.Outside);
        try
        {
            f.SetupFetch(Path.Combine(sub, "a.txt"));
            Assert.Equal(TaskResultStatus.Rejected, (await f.RunFetch()).Status);

            foreach (var path in new[] { f.Root + @"\..\Secret\a.txt", f.Root + @"\a.txt:fluxo", @"\\servidor\pasta\a.txt", f.Root + @"\CON" })
            {
                f.SetupFetch(path);
                Assert.Equal(TaskResultStatus.Rejected, (await f.RunFetch()).Status);
            }
        }
        finally
        {
            Directory.Delete(sub);
        }

        Assert.Empty(f.Api.Put);
        Assert.Empty(f.Api.Begins);
    }

    [Fact]
    public async Task Fetch_of_a_hard_link_to_an_outside_file_is_rejected()
    {
        using var f = new Fixture();
        var secret = Path.Combine(f.Outside, "segredo.txt");
        File.WriteAllBytes(secret, Bytes("segredo"));
        var link = Path.Combine(f.Root, "h.txt");
        Assert.True(CreateHardLinkW(link, secret, IntPtr.Zero),
            "Ambiente não permitiu criar hard link (CreateHardLinkW); o teste NÃO foi executado. Erro Win32 " + Marshal.GetLastWin32Error());
        f.SetupFetch(link);

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Empty(f.Api.Put);
        File.Delete(link);
    }

    [Fact]
    public async Task Fetch_with_wrong_direction_or_endpoint_is_rejected()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123"));
        f.SetupFetch(file);
        var good = f.Api.Info!;

        f.Api.Info = good with { Direction = TransferProtocol.Send };
        Assert.Equal(TaskResultStatus.Rejected, (await f.RunFetch()).Status);
        f.Api.Info = good with { EndpointId = "00000000-0000-4000-8000-000000000000" };
        Assert.Equal(TaskResultStatus.Rejected, (await f.RunFetch()).Status);

        Assert.Empty(f.Api.Begins);
    }

    [Fact]
    public async Task Fetch_too_large_for_the_policy_is_rejected()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123"));
        f.SetupFetch(file);
        f.Api.BeginError = new ManagerRejectedException(422, "transfer.too_large", "O arquivo excede o limite da política.");

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Rejected, outcome.Status);
        Assert.Contains("limite", outcome.Error);
        Assert.Empty(f.Api.Put);
    }

    [Fact]
    public async Task Fetch_failed_status_on_complete_is_failed()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123"));
        f.SetupFetch(file);
        f.Api.CompleteStatus = "failed";

        Assert.Equal(TaskResultStatus.Failed, (await f.RunFetch()).Status);
    }

    [Fact]
    public async Task Fetch_conflict_cancels()
    {
        using var f = new Fixture();
        var file = Path.Combine(f.Root, "log.txt");
        File.WriteAllBytes(file, Bytes("0123456789"));
        f.SetupFetch(file);
        f.Api.ChunkError = n => n == 1 ? new ManagerRejectedException(409, "transfer.conflict", "x") : null;

        var outcome = await f.RunFetch();

        Assert.Equal(TaskResultStatus.Cancelled, outcome.Status);
        Assert.Equal([0], f.Api.Put.Keys);
        Assert.Empty(f.Api.Completes);
    }

    // ---------------------------------------------------------------- Throttle

    [Fact]
    public async Task Throttle_paces_bytes_per_second_with_the_simulated_clock()
    {
        var time = new FakeTime(DateTimeOffset.Parse("2026-10-16T12:00:00Z"));
        var delays = new List<TimeSpan>();
        var throttle = new Throttle(1000, time, (d, _) =>
        {
            delays.Add(d);
            time.Advance(d);

            return Task.CompletedTask;
        });

        await throttle.WaitAsync(1000, default);   // dentro da rajada inicial (1 s)
        Assert.Empty(delays);
        await throttle.WaitAsync(2000, default);
        Assert.Equal([TimeSpan.FromSeconds(2)], delays);
        await throttle.WaitAsync(500, default);
        Assert.Equal(TimeSpan.FromSeconds(0.5), delays[^1]);

        time.Advance(TimeSpan.FromSeconds(10));    // ociosidade não acumula mais que 1 s de crédito
        delays.Clear();
        await throttle.WaitAsync(1000, default);
        Assert.Empty(delays);
        await throttle.WaitAsync(1000, default);
        Assert.Equal([TimeSpan.FromSeconds(1)], delays);

        var elapsed = time.GetUtcNow() - DateTimeOffset.Parse("2026-10-16T12:00:00Z");
        Assert.Equal(TimeSpan.FromSeconds(13.5), elapsed);   // nunca dorme mais que o necessário
    }

    [Fact]
    public async Task Throttle_respects_cancellation()
    {
        var throttle = new Throttle(AgentSettings.MinTransferBytesPerSecond, TimeProvider.System);
        using var cts = new CancellationTokenSource();
        await throttle.WaitAsync(1, default);
        cts.Cancel();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => throttle.WaitAsync(10 * 1024 * 1024, cts.Token));
    }

    // ---------------------------------------------------------------- raízes e ACL

    [Fact]
    public void Root_helper_creates_the_directory_with_a_restricted_acl_when_elevated()
    {
        using var dir = new TempDir();
        var root = Path.Combine(dir.Path, "Inbox");

        var applied = TransferRootSetup.CreateRestricted(root);

        Assert.True(Directory.Exists(root));
        Assert.Equal(AgentPaths.IsElevated(), applied);
        if (!applied)
        {
            output.WriteLine("Sessão NÃO elevada: a ACL restrita não foi aplicada nem conferida neste ambiente (só a criação da pasta).");

            return;
        }

        var security = new DirectoryInfo(root).GetAccessControl();
        Assert.True(security.AreAccessRulesProtected);   // sem herança do pai
        var sids = security.GetAccessRules(true, true, typeof(SecurityIdentifier)).Cast<FileSystemAccessRule>()
            .Select(r => ((SecurityIdentifier)r.IdentityReference).Value).Distinct().Order().ToList();
        Assert.Equal(new[]
        {
            new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null).Value,
            new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null).Value,
        }.Order(), sids);
    }

    [Fact]
    public void Prepare_creates_missing_roots_and_skips_invalid_ones()
    {
        using var dir = new TempDir();
        var inbox = Path.Combine(dir.Path, "Inbox");
        var logs = Path.Combine(dir.Path, "Logs");
        var settings = new AgentSettings { TransferRoots = [inbox, "relativo"], TransferFetchOnlyRoots = [logs] };

        var prepared = TransferRootSetup.Prepare(settings, NullLogger.Instance, ownBase: dir.Path);

        Assert.True(Directory.Exists(inbox));
        Assert.True(Directory.Exists(logs));
        Assert.Equal([inbox, logs], prepared);
    }

    // ---------------------------------------------------------------- catálogo, validador e runner

    [Fact]
    public void Local_catalog_has_both_file_types_with_900_seconds()
    {
        Assert.True(LocalTaskCatalog.IsKnown("file.send"));
        Assert.True(LocalTaskCatalog.IsKnown("file.fetch"));
        Assert.Equal(TimeSpan.FromSeconds(900), LocalTaskCatalog.Timeout("file.send"));
        Assert.Equal(TimeSpan.FromSeconds(900), LocalTaskCatalog.Timeout("file.fetch"));
    }

    [Theory]
    [InlineData("file.send")]
    [InlineData("file.fetch")]
    public void Schema_for_file_types_is_strict(string type)
    {
        Assert.True(TaskSchemaValidator.Validate(type, Params()).Ok);

        foreach (var bad in new[]
        {
            "{}",
            "{\"transfer_id\":\"3D6F0A2B-5C4E-4B1A-9F8E-7D6C5B4A3F21\"}",
            "{\"transfer_id\":\"{3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21}\"}",
            "{\"transfer_id\":\"3d6f0a2b5c4e4b1a9f8e7d6c5b4a3f21\"}",
            "{\"transfer_id\":\"3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21\\n\"}",
            "{\"transfer_id\":\" 3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21\"}",
            "{\"transfer_id\":\"..\\\\..\\\\a\"}",
            "{\"transfer_id\":123}",
            "{\"transfer_id\":null}",
            "{\"transfer_id\":[\"3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21\"]}",
            "{\"transfer_id\":\"3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21\",\"remote_path\":\"C:\\\\x\"}",
            "{\"transfer_id\":\"3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21\",\"transfer_id\":\"00000000-0000-4000-8000-000000000000\"}",
            "[]",
        })
        {
            Assert.False(TaskSchemaValidator.Validate(type, TaskExecutionTests.P(bad)).Ok, bad);
        }
    }

    [Fact]
    public async Task Runner_rejects_invalid_file_params_without_calling_the_api()
    {
        using var f = new Fixture();
        var runner = TaskExecutionTests.Runner(null, f.Send(), f.Fetch());

        var upper = await runner.RunAsync(TaskExecutionTests.Assignment("file.send", "{\"transfer_id\":\"3D6F0A2B-5C4E-4B1A-9F8E-7D6C5B4A3F21\"}"), default);
        var extra = await runner.RunAsync(TaskExecutionTests.Assignment("file.fetch", "{\"transfer_id\":\"" + TransferId + "\",\"x\":1}"), default);

        Assert.Equal(TaskResultStatus.Rejected, upper.Status);
        Assert.Equal(TaskResultStatus.Rejected, extra.Status);
        Assert.Equal(0, f.Api.GetCalls);
    }

    private sealed class ContextSpy(string type, Func<TaskContext, CancellationToken, Task<TaskOutcome>> run) : IContextTaskHandler
    {
        public string Type { get; } = type;
        public int PlainCalls;
        public TaskContext? Seen;

        public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
        {
            PlainCalls++;

            return Task.FromResult(TaskOutcome.Failed("contexto não usado"));
        }

        public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, TaskContext context, CancellationToken ct)
        {
            Seen = context;

            return run(context, ct);
        }
    }

    private sealed class RecordingSink(Func<int, Exception?>? fail = null) : ITaskProgressSink
    {
        public readonly List<(string TaskId, string AttemptId, int Percent)> Calls = [];

        public Task ReportAsync(string taskId, string attemptId, int percent, CancellationToken ct)
        {
            Calls.Add((taskId, attemptId, percent));

            return fail?.Invoke(percent) is { } ex ? Task.FromException(ex) : Task.CompletedTask;
        }
    }

    [Fact]
    public async Task Runner_uses_the_context_handler_and_reports_clamped_progress()
    {
        var sink = new RecordingSink();
        var spy = new ContextSpy("file.send", async (ctx, ct) =>
        {
            await ctx.ReportProgress(50, ct);
            await ctx.ReportProgress(150, ct);
            await ctx.ReportProgress(-3, ct);

            return TaskOutcome.Completed("ok");
        });
        var runner = new TaskRunner([spy], new TaskCoordinator(), TimeProvider.System, NullLogger<TaskRunner>.Instance, sink);
        var a = TaskExecutionTests.Assignment("file.send", "{\"transfer_id\":\"" + TransferId + "\"}");

        var result = await runner.RunAsync(a, default);

        Assert.Equal(TaskResultStatus.Completed, result.Status);
        Assert.Equal(0, spy.PlainCalls);
        Assert.Equal(a.TaskId, spy.Seen!.TaskId);
        Assert.Equal(a.AttemptId, spy.Seen.AttemptId);
        Assert.Equal([50, 99, 0], sink.Calls.Select(c => c.Percent));
        Assert.All(sink.Calls, c => Assert.Equal((a.TaskId, a.AttemptId), (c.TaskId, c.AttemptId)));
    }

    [Fact]
    public async Task Runner_swallows_progress_failures()
    {
        var sink = new RecordingSink(_ => new ManagerUnavailableException("rede"));
        var spy = new ContextSpy("file.fetch", async (ctx, ct) =>
        {
            await ctx.ReportProgress(10, ct);
            await ctx.ReportProgress(20, ct);

            return TaskOutcome.Completed("ok");
        });
        var runner = new TaskRunner([spy], new TaskCoordinator(), TimeProvider.System, NullLogger<TaskRunner>.Instance, sink);

        var result = await runner.RunAsync(TaskExecutionTests.Assignment("file.fetch", "{\"transfer_id\":\"" + TransferId + "\"}"), default);

        Assert.Equal(TaskResultStatus.Completed, result.Status);
        Assert.Equal(2, sink.Calls.Count);
    }

    [Fact]
    public async Task Runner_cancels_the_handler_when_progress_says_the_attempt_is_gone()
    {
        var sink = new RecordingSink(_ => new ManagerRejectedException(409, "task.conflict", "x"));
        var stopped = false;
        var spy = new ContextSpy("file.send", async (ctx, ct) =>
        {
            await ctx.ReportProgress(10, ct);
            try
            {
                await Task.Delay(TimeSpan.FromSeconds(30), ct);
            }
            catch (OperationCanceledException)
            {
                stopped = true;
                throw;
            }

            return TaskOutcome.Completed("não deveria");
        });
        var runner = new TaskRunner([spy], new TaskCoordinator(), TimeProvider.System, NullLogger<TaskRunner>.Instance, sink);

        var result = await runner.RunAsync(TaskExecutionTests.Assignment("file.send", "{\"transfer_id\":\"" + TransferId + "\"}"), default);

        Assert.Equal(TaskResultStatus.Cancelled, result.Status);
        await Task.Delay(100);
        Assert.True(stopped);
    }

    [Fact]
    public async Task Runner_without_a_sink_still_runs_context_handlers()
    {
        var spy = new ContextSpy("file.send", async (ctx, ct) =>
        {
            await ctx.ReportProgress(10, ct);

            return TaskOutcome.Completed("ok");
        });

        var result = await TaskExecutionTests.Runner(null, spy).RunAsync(TaskExecutionTests.Assignment("file.send", "{\"transfer_id\":\"" + TransferId + "\"}"), default);

        Assert.Equal(TaskResultStatus.Completed, result.Status);
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateHardLinkW(string lpFileName, string lpExistingFileName, IntPtr lpSecurityAttributes);
}
