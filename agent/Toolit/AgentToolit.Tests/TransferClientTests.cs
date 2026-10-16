using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Transfers;
using AgentToolit.Tests.Support;

namespace AgentToolit.Tests;

/// <summary>Cliente das rotas de transferência (ITransferApi no ManagerClient) e configurações de transferência.</summary>
public class TransferClientTests
{
    private const string Id = "3d6f0a2b-5c4e-4b1a-9f8e-7d6c5b4a3f21";

    private static string Contract(string name)
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null && !File.Exists(Path.Combine(dir.FullName, "tests", "contracts", name)))
        {
            dir = dir.Parent;
        }

        Assert.NotNull(dir);

        return File.ReadAllText(Path.Combine(dir!.FullName, "tests", "contracts", name));
    }

    private static string Hex(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();

    private static void AssertSigned(CapturedRequest sent, Core.Identity.AgentIdentity identity)
    {
        var canonical = RequestSigner.Canonical(sent.Method, sent.Path, "", sent.Headers["X-Timestamp"], sent.Headers["X-Nonce"], sent.Body);
        Assert.True(RequestSigner.Verify(identity.Key, canonical, sent.Headers["X-Signature"]));
    }

    private static HttpResponseMessage Binary(byte[] bytes, string? hash)
    {
        var response = new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(bytes) };
        response.Content.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");
        if (hash is not null)
        {
            response.Headers.TryAddWithoutValidation("X-Chunk-Sha256", hash);
        }

        return response;
    }

    [Fact]
    public async Task Get_signs_the_request_and_parses_the_send_contract()
    {
        using var dir = new TempDir();
        var json = Contract("transfer-metadata.json");
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, json));

        var info = await client.GetAsync(Id, default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("GET", sent.Method);
        Assert.Equal($"/api/agent/v1/transfers/{Id}", sent.Path);
        Assert.Empty(sent.Body);
        AssertSigned(sent, identity);

        Assert.Equal(Id, info.TransferId);
        Assert.Equal("8a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d", info.EndpointId);
        Assert.Equal("send", info.Direction);
        Assert.Equal(5243880L, info.Size);
        Assert.Equal("9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08", info.Sha256);
        Assert.Equal(2097152, info.ChunkSize);
        Assert.Equal(3, info.ChunksTotal);
        Assert.Equal(@"C:\ProgramData\Toolit\Inbox\relatorio.pdf", info.RemotePath);
        Assert.False(info.Overwrite);
        Assert.Empty(info.ReceivedChunks);

        // As 10 chaves do contrato, nem mais nem menos, ao reserializar.
        using var again = JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(info, new JsonSerializerOptions(Json.Options)
        {
            DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.Never,
        }));
        using var expected = JsonDocument.Parse(json);
        Assert.Equal(
            expected.RootElement.EnumerateObject().Select(p => p.Name).Order(),
            again.RootElement.EnumerateObject().Select(p => p.Name).Order());
    }

    [Fact]
    public async Task Get_parses_the_fetch_contract_with_nulls_before_begin()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, Contract("transfer-metadata-fetch.json")));

        var info = await client.GetAsync("5e2a9c41-7b3d-4f60-a8e1-2c9d4b6f7a10", default);

        Assert.Equal("fetch", info.Direction);
        Assert.Null(info.Size);
        Assert.Null(info.Sha256);
        Assert.Null(info.ChunksTotal);
        Assert.Equal(2097152, info.ChunkSize);
        Assert.Equal(@"C:\ProgramData\Toolit\Outbox\diag.log", info.RemotePath);
    }

    [Fact]
    public async Task Get_received_chunks_are_parsed()
    {
        using var dir = new TempDir();
        var json = Contract("transfer-metadata.json").Replace("\"received_chunks\": []", "\"received_chunks\": [0, 2]");
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, json));

        Assert.Equal(new[] { 0, 2 }, (await client.GetAsync(Id, default)).ReceivedChunks);
    }

    [Theory]
    [InlineData("{}")]
    [InlineData("{\"transfer_id\":\"x\",\"endpoint_id\":\"e\",\"direction\":\"delete\",\"chunk_size\":1,\"remote_path\":\"C:\\\\a\",\"overwrite\":false,\"received_chunks\":[]}")]
    [InlineData("{\"transfer_id\":\"x\",\"endpoint_id\":\"e\",\"direction\":\"send\",\"chunk_size\":1,\"overwrite\":false,\"received_chunks\":[]}")]
    [InlineData("{\"transfer_id\":\"x\",\"endpoint_id\":\"e\",\"direction\":\"send\",\"chunk_size\":0,\"remote_path\":\"C:\\\\a\",\"overwrite\":false,\"received_chunks\":[]}")]
    [InlineData("{\"transfer_id\":\"x\",\"endpoint_id\":\"e\",\"direction\":\"send\",\"chunk_size\":\"big\",\"remote_path\":\"C:\\\\a\",\"overwrite\":false,\"received_chunks\":[]}")]
    [InlineData("[]")]
    public async Task Get_refuses_incomplete_or_malformed_metadata(string body)
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, body));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.GetAsync(Id, default));
        Assert.Equal("response.invalid", ex.Code);
    }

    [Fact]
    public async Task Get_chunk_returns_the_raw_bytes_and_the_hash_header()
    {
        using var dir = new TempDir();
        var bytes = new byte[] { 0, 1, 2, 0xFF, (byte)'{', 0x80 };   // nada de JSON aqui
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => Binary(bytes, Hex(bytes)));

        var (got, hash) = await client.GetChunkAsync(Id, 2, default);

        Assert.Equal(bytes, got);
        Assert.Equal(Hex(bytes), hash);
        var sent = Assert.Single(handler.Requests);
        Assert.Equal("GET", sent.Method);
        Assert.Equal($"/api/agent/v1/transfers/{Id}/chunks/2", sent.Path);
        Assert.Empty(sent.Body);
        AssertSigned(sent, identity);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("abc")]
    [InlineData("9F86D081884C7D659A2FEAA0C55AD015A3BF4F1B2B0B822CD15D6C15B0F00A08")]   // maiúsculas: fora do contrato
    [InlineData("9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a0g")]
    [InlineData("9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08 ")]
    public async Task Get_chunk_rejects_a_missing_or_invalid_hash_header(string? header)
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => Binary([1, 2, 3], header));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.GetChunkAsync(Id, 0, default));
        Assert.Equal("response.invalid", ex.Code);
    }

    [Fact]
    public async Task Get_chunk_rejects_bytes_that_do_not_match_the_header()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => Binary([1, 2, 3], Hex([1, 2, 4])));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.GetChunkAsync(Id, 0, default));
        Assert.Equal("transfer.chunk_hash", ex.Code);
        Assert.Single(handler.Requests);   // sem retentativa interna
    }

    [Fact]
    public async Task Get_chunk_rejects_a_negative_index_without_calling_the_manager()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => Binary([], Hex([])));

        await Assert.ThrowsAsync<ArgumentOutOfRangeException>(() => client.GetChunkAsync(Id, -1, default));
        Assert.Empty(handler.Requests);
    }

    [Fact]
    public async Task Put_chunk_sends_raw_bytes_with_hash_header_and_signs_those_exact_bytes()
    {
        using var dir = new TempDir();
        var bytes = new byte[4096];
        new Random(42).NextBytes(bytes);
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{\"chunks_received\":1}"));

        await client.PutChunkAsync(Id, 0, bytes, Hex(bytes), default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("PUT", sent.Method);
        Assert.Equal($"/api/agent/v1/transfers/{Id}/chunks/0", sent.Path);
        Assert.Equal(bytes, sent.Body);
        Assert.Equal(Hex(bytes), sent.Headers["X-Chunk-Sha256"]);
        Assert.Equal("application/octet-stream", sent.ContentType);
        AssertSigned(sent, identity);

        // Um byte diferente invalida a assinatura: ela cobre exatamente o corpo enviado.
        var tampered = (byte[])sent.Body.Clone();
        tampered[0] ^= 1;
        Assert.False(RequestSigner.Verify(identity.Key,
            RequestSigner.Canonical("PUT", sent.Path, "", sent.Headers["X-Timestamp"], sent.Headers["X-Nonce"], tampered), sent.Headers["X-Signature"]));
    }

    [Fact]
    public async Task Put_chunk_refuses_a_malformed_hash_without_calling_the_manager()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));

        await Assert.ThrowsAsync<ArgumentException>(() => client.PutChunkAsync(Id, 0, [1], "ABC", default));
        await Assert.ThrowsAsync<ArgumentException>(() => client.PutChunkAsync(Id, 0, [1], Hex([1]).ToUpperInvariant(), default));
        await Assert.ThrowsAsync<ArgumentException>(() => client.PutChunkAsync(Id, 0, new byte[TransferProtocol.MaxChunkBytes + 1], Hex([1]), default));
        Assert.Empty(handler.Requests);
    }

    [Fact]
    public async Task Begin_posts_size_in_snake_case()
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, _ =>
            StubHandler.Json(HttpStatusCode.OK, "{\"status\":\"uploading\",\"chunk_size\":2097152,\"chunks_total\":3}"));

        await client.BeginAsync(Id, 5243880, default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("POST", sent.Method);
        Assert.Equal($"/api/agent/v1/transfers/{Id}/begin", sent.Path);
        Assert.Equal("{\"size\":5243880}", sent.BodyText);
        Assert.Equal("application/json", sent.ContentType);
        AssertSigned(sent, identity);
    }

    [Theory]
    [InlineData("completed")]
    [InlineData("failed")]
    public async Task Complete_posts_hash_and_size_and_returns_the_status(string status)
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{\"status\":\"" + status + "\"}"));
        var hash = Hex([1, 2, 3]);

        Assert.Equal(status, await client.CompleteAsync(Id, hash, 3, default));

        var sent = Assert.Single(handler.Requests);
        Assert.Equal($"/api/agent/v1/transfers/{Id}/complete", sent.Path);
        Assert.Equal("{\"sha256\":\"" + hash + "\",\"size\":3}", sent.BodyText);
        AssertSigned(sent, identity);
    }

    [Fact]
    public async Task Complete_without_status_is_an_invalid_response()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.CompleteAsync(Id, Hex([1]), 1, default));
        Assert.Equal("response.invalid", ex.Code);
    }

    public static TheoryData<HttpStatusCode, string> Rejections => new()
    {
        { HttpStatusCode.NotFound, "request.not_found" },
        { HttpStatusCode.Conflict, "transfer.conflict" },
        { HttpStatusCode.UnprocessableEntity, "transfer.chunk_hash" },
        { HttpStatusCode.UnprocessableEntity, "transfer.too_large" },
        { HttpStatusCode.UnprocessableEntity, "transfer.invalid" },
        { HttpStatusCode.RequestEntityTooLarge, "request.too_large" },
        { HttpStatusCode.UnsupportedMediaType, "request.unsupported_media_type" },
    };

    [Theory]
    [MemberData(nameof(Rejections))]
    public async Task Errors_map_to_rejected_with_the_manager_code_and_are_not_retried(HttpStatusCode status, string code)
    {
        var calls = new Func<ITransferApi, Task>[]
        {
            api => api.GetAsync(Id, default),
            api => api.GetChunkAsync(Id, 0, default),
            api => api.PutChunkAsync(Id, 0, [1], Hex([1]), default),
            api => api.BeginAsync(Id, 1, default),
            api => api.CompleteAsync(Id, Hex([1]), 1, default),
        };

        foreach (var call in calls)
        {
            using var dir = new TempDir();
            var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Error(status, code));

            var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => call(client));
            Assert.Equal((int)status, ex.Status);
            Assert.Equal(code, ex.Code);
            Assert.Single(handler.Requests);
        }
    }

    [Theory]
    [InlineData(HttpStatusCode.InternalServerError)]
    [InlineData(HttpStatusCode.BadGateway)]
    [InlineData(HttpStatusCode.ServiceUnavailable)]
    public async Task Server_errors_surface_as_unavailable_with_a_single_request(HttpStatusCode status)
    {
        var calls = new Func<ITransferApi, Task>[]
        {
            api => api.GetAsync(Id, default),
            api => api.GetChunkAsync(Id, 0, default),
            api => api.PutChunkAsync(Id, 0, [1], Hex([1]), default),
            api => api.BeginAsync(Id, 1, default),
            api => api.CompleteAsync(Id, Hex([1]), 1, default),
        };

        foreach (var call in calls)
        {
            using var dir = new TempDir();
            var (client, _, _, handler) = Enrolled.Create(dir, _ => new HttpResponseMessage(status));

            await Assert.ThrowsAsync<ManagerUnavailableException>(() => call(client));
            Assert.Single(handler.Requests);   // o handler decide se repete; o cliente nunca
        }
    }

    [Fact]
    public async Task Revoked_installation_is_mapped_and_a_revoked_agent_sends_nothing()
    {
        using var dir = new TempDir();
        var (client, runtime, _, handler) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));

        await Assert.ThrowsAsync<AgentRevokedException>(() => client.GetChunkAsync(Id, 0, default));
        Assert.Single(handler.Requests);

        runtime.MarkRevoked();
        await Assert.ThrowsAsync<AgentRevokedException>(() => client.PutChunkAsync(Id, 0, [1], Hex([1]), default));
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Transfer_id_is_escaped_in_the_route()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.NotFound, "request.not_found"));

        await Assert.ThrowsAsync<ManagerRejectedException>(() => client.GetAsync("../tasks/next", default));

        Assert.Equal("/api/agent/v1/transfers/..%2Ftasks%2Fnext", Assert.Single(handler.Requests).Path);
    }

    // ---------------------------------------------------------------- configurações

    [Fact]
    public void Transfer_settings_have_safe_defaults_under_program_data()
    {
        var programData = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
        var settings = new AgentSettings();

        Assert.Equal(new[] { Path.Combine(programData, "Toolit", "Inbox"), Path.Combine(programData, "Toolit", "Outbox") }, settings.TransferRoots);
        Assert.Equal(new[] { Path.Combine(programData, "Toolit", "Logs") }, settings.TransferFetchOnlyRoots);
        Assert.Equal(4L * 1024 * 1024, settings.TransferBytesPerSecond);
        Assert.All(settings.TransferRoots.Concat(settings.TransferFetchOnlyRoots), r => Assert.Null(PathGuard.ValidateDirectory(r)));
    }

    [Fact]
    public void Transfer_bandwidth_is_bounded()
    {
        Assert.Equal(64L * 1024, new AgentSettings { TransferBytesPerSecond = 1 }.TransferBytesPerSecond);
        Assert.Equal(64L * 1024, new AgentSettings { TransferBytesPerSecond = -5 }.TransferBytesPerSecond);
        Assert.Equal(64L * 1024 * 1024, new AgentSettings { TransferBytesPerSecond = long.MaxValue }.TransferBytesPerSecond);
        Assert.Equal(1_000_000L, new AgentSettings { TransferBytesPerSecond = 1_000_000 }.TransferBytesPerSecond);
        Assert.Equal(AgentSettings.MinTransferBytesPerSecond, 64L * 1024);
        Assert.Equal(AgentSettings.MaxTransferBytesPerSecond, 64L * 1024 * 1024);
    }

    [Fact]
    public void Transfer_settings_round_trip_through_config_json()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        var store = new AgentSettingsStore(paths);
        store.Save(new AgentSettings
        {
            TransferRoots = [@"D:\Dados\Inbox", @"D:\Dados\Outbox"],
            TransferFetchOnlyRoots = [],
            TransferBytesPerSecond = 1_048_576,
        });

        var loaded = store.Load();

        Assert.Equal(new[] { @"D:\Dados\Inbox", @"D:\Dados\Outbox" }, loaded.TransferRoots);   // substitui o padrão, não soma
        Assert.Empty(loaded.TransferFetchOnlyRoots);
        Assert.Equal(1_048_576L, loaded.TransferBytesPerSecond);
    }

    [Fact]
    public void Transfer_settings_from_a_hand_edited_config_are_bounded_and_null_lists_are_empty()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        File.WriteAllText(paths.ConfigFile, "{\"TransferRoots\":null,\"TransferBytesPerSecond\":999999999999}");

        var loaded = new AgentSettingsStore(paths).Load();

        Assert.Empty(loaded.TransferRoots);   // nulo = transferência desligada, nunca "qualquer lugar"
        Assert.Equal(AgentSettings.MaxTransferBytesPerSecond, loaded.TransferBytesPerSecond);
        Assert.Single(loaded.TransferFetchOnlyRoots);   // ausente = padrão
    }

    [Fact]
    public void Old_config_without_transfer_keys_gets_the_defaults()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        File.WriteAllText(paths.ConfigFile, "{\"ServerUrl\":\"https://m.local/\"}");

        var loaded = new AgentSettingsStore(paths).Load();

        Assert.Equal(new AgentSettings().TransferRoots, loaded.TransferRoots);
        Assert.Equal(AgentSettings.DefaultTransferBytesPerSecond, loaded.TransferBytesPerSecond);
    }
}
