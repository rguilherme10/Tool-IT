using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Logging;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Queue;
using AgentToolit.Tests.Support;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Tests;

public class SignatureContractTests
{
    /// <summary>Vetor compartilhado com o Manager (tests/contracts/signature-vector.json), gerado pelo lado PHP.</summary>
    private static JsonElement Vector()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null && !File.Exists(Path.Combine(dir.FullName, "tests", "contracts", "signature-vector.json")))
        {
            dir = dir.Parent;
        }

        Assert.NotNull(dir);

        return JsonDocument.Parse(File.ReadAllText(Path.Combine(dir!.FullName, "tests", "contracts", "signature-vector.json"))).RootElement;
    }

    [Fact]
    public void Canonical_string_matches_the_manager_vector_byte_for_byte()
    {
        var v = Vector();
        var r = v.GetProperty("request");

        var canonical = RequestSigner.Canonical(
            r.GetProperty("method").GetString()!, r.GetProperty("path").GetString()!, r.GetProperty("query").GetString()!,
            r.GetProperty("timestamp").GetString()!, r.GetProperty("nonce").GetString()!, Encoding.UTF8.GetBytes(r.GetProperty("body").GetString()!));

        Assert.Equal(v.GetProperty("canonical_string").GetString(), canonical);
    }

    [Fact]
    public void Signature_produced_by_the_manager_side_openssl_verifies_in_dotnet()
    {
        var v = Vector();
        using var publicKey = ECDsa.Create();
        publicKey.ImportSubjectPublicKeyInfo(Convert.FromBase64String(v.GetProperty("public_key_spki_der_base64").GetString()!), out _);

        Assert.True(RequestSigner.Verify(publicKey, v.GetProperty("canonical_string").GetString()!, v.GetProperty("signature_der_base64").GetString()!));
        Assert.False(RequestSigner.Verify(publicKey, v.GetProperty("canonical_string").GetString() + "x", v.GetProperty("signature_der_base64").GetString()!));
    }

    [Fact]
    public void Own_signature_roundtrips_and_is_der_encoded()
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var signature = RequestSigner.Sign(key, "v1\nPOST\n/x\n\n1\nn\nabc");

        Assert.True(RequestSigner.Verify(key, "v1\nPOST\n/x\n\n1\nn\nabc", signature));
        Assert.Equal(0x30, Convert.FromBase64String(signature)[0]); // SEQUENCE DER (RFC 3279), não IEEE P1363 de 64 bytes
    }

    [Fact]
    public void Query_order_does_not_change_the_canonical_string()
    {
        var a = RequestSigner.Canonical("GET", "/p", "b=2&a=1", "1", "n", []);
        var b = RequestSigner.Canonical("get", "/p", "a=1&b=2", "1", "n", []);

        Assert.Equal(a, b);
    }

    [Fact]
    public void Nonces_are_unique_url_safe_and_accepted_by_the_manager_format()
    {
        var nonces = Enumerable.Range(0, 1000).Select(_ => RequestSigner.NewNonce()).ToList();

        Assert.Equal(1000, nonces.Distinct().Count());
        Assert.All(nonces, n => Assert.Matches("^[A-Za-z0-9_-]{16,64}$", n));
    }
}

public class BackoffTests
{
    private sealed class FixedRandom(double value) : Random
    {
        public override double NextDouble() => value;
    }

    [Fact]
    public void Grows_exponentially_from_5_seconds_and_caps_at_15_minutes()
    {
        var backoff = new Backoff(random: new FixedRandom(0.5)); // jitter neutro (1.0)
        var delays = Enumerable.Range(0, 12).Select(_ => backoff.Next().TotalSeconds).ToList();

        Assert.Equal(new double[] { 5, 10, 20, 40, 80, 160, 320, 640, 900, 900, 900, 900 }, delays.Select(d => Math.Round(d)));
    }

    [Fact]
    public void Jitter_stays_within_20_percent_and_reset_restarts()
    {
        foreach (var r in new[] { 0.0, 0.999 })
        {
            var backoff = new Backoff(random: new FixedRandom(r));
            var seconds = backoff.Next().TotalSeconds;
            Assert.InRange(seconds, 4.0, 6.0);
        }

        var b = new Backoff(random: new FixedRandom(0.5));
        b.Next();
        b.Next();
        b.Reset();
        Assert.Equal(5, Math.Round(b.Next().TotalSeconds));
    }
}

public class ServerUrlPolicyTests
{
    [Theory]
    [InlineData("https://manager.empresa.local", true)]
    [InlineData("https://manager.empresa.local/toolit/public", true)]
    [InlineData("http://127.0.0.1:8000", true)]
    [InlineData("http://localhost", true)]
    [InlineData("http://manager.empresa.local", false)]
    [InlineData("ftp://manager", false)]
    [InlineData("https://user:pass@manager", false)]
    [InlineData("https://manager/?token=1", false)]
    [InlineData("não é url", false)]
    [InlineData("", false)]
    [InlineData(null, false)]
    public void Accepts_only_https_or_loopback_http(string? url, bool expected)
    {
        Assert.Equal(expected, ServerUrlPolicy.TryValidate(url, out _, out _));
    }

    [Fact]
    public void Normalizes_trailing_slash_preserving_install_prefix()
    {
        ServerUrlPolicy.TryValidate("https://m.local/toolit/public", out var uri, out _);

        Assert.Equal("https://m.local/toolit/public/", uri!.ToString());
    }
}

public class OutboundQueueTests
{
    [Fact]
    public void Is_fifo_per_kind_and_deletes_only_what_is_acknowledged()
    {
        using var dir = new TempDir();
        using var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), 100, 1_000_000);
        queue.Enqueue(OutboundQueue.Metrics, [1], 5);
        queue.Enqueue(OutboundQueue.Inventory, [2], 10);
        queue.Enqueue(OutboundQueue.Metrics, [3], 5);

        var metrics = queue.Peek(OutboundQueue.Metrics, 10);
        Assert.Equal([(byte)1, (byte)3], metrics.Select(m => m.Payload[0]));

        queue.Delete([metrics[0].Id]);
        Assert.Equal(2, queue.Count());
        Assert.Single(queue.Peek(OutboundQueue.Metrics, 10));
        Assert.Single(queue.Peek(OutboundQueue.Inventory, 10));
    }

    [Fact]
    public void Survives_restart()
    {
        using var dir = new TempDir();
        var file = Path.Combine(dir.Path, "q.db");
        using (var queue = new OutboundQueue(file, 100, 1_000_000))
        {
            queue.Enqueue(OutboundQueue.Metrics, Encoding.UTF8.GetBytes("pendente"), 5);
        }

        using var reopened = new OutboundQueue(file, 100, 1_000_000);
        Assert.Equal("pendente", Encoding.UTF8.GetString(reopened.Peek(OutboundQueue.Metrics, 1)[0].Payload));
    }

    [Fact]
    public void Item_limit_drops_lowest_priority_then_oldest()
    {
        using var dir = new TempDir();
        using var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), maxItems: 3, maxBytes: 1_000_000);
        queue.Enqueue(OutboundQueue.Inventory, [100], priority: 10);
        queue.Enqueue(OutboundQueue.Metrics, [1], priority: 5);
        queue.Enqueue(OutboundQueue.Metrics, [2], priority: 5);

        var dropped = queue.Enqueue(OutboundQueue.Metrics, [3], priority: 5);

        Assert.Equal(1, dropped);
        Assert.Equal(3, queue.Count());
        Assert.Equal([(byte)2, (byte)3], queue.Peek(OutboundQueue.Metrics, 10).Select(m => m.Payload[0])); // a métrica 1 (mais antiga) caiu
        Assert.Single(queue.Peek(OutboundQueue.Inventory, 10)); // prioridade alta preservada
    }

    [Fact]
    public void Byte_limit_is_enforced()
    {
        using var dir = new TempDir();
        using var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), maxItems: 1000, maxBytes: 250);
        for (var i = 0; i < 10; i++)
        {
            queue.Enqueue(OutboundQueue.Metrics, new byte[100], 5);
        }

        Assert.True(queue.TotalBytes() <= 250);
        Assert.Equal(2, queue.Count());
    }

    [Fact]
    public void Attempts_counter_increments()
    {
        using var dir = new TempDir();
        using var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), 10, 1000);
        queue.Enqueue(OutboundQueue.Metrics, [1], 5);
        var id = queue.Peek(OutboundQueue.Metrics, 1)[0].Id;

        queue.MarkAttempt([id]);
        queue.MarkAttempt([id]);

        Assert.Equal(2, queue.Peek(OutboundQueue.Metrics, 1)[0].Attempts);
    }
}

public class IdentityAndConfigTests
{
    [Fact]
    public void Private_key_is_protected_on_disk_and_roundtrips()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        using var identity = AgentIdentity.Generate();
        var pkcs8 = identity.Key.ExportPkcs8PrivateKey();
        identity.Save(paths);

        var onDisk = File.ReadAllBytes(paths.IdentityFile);
        Assert.False(onDisk.AsSpan().IndexOf(pkcs8.AsSpan(0, 32)) >= 0, "a chave privada não pode estar em claro no arquivo");

        using var loaded = AgentIdentity.TryLoad(paths)!;
        Assert.Equal(identity.PublicKeyBase64, loaded.PublicKeyBase64);

        var canonical = RequestSigner.Canonical("POST", "/x", "", "1", "n", []);
        Assert.True(RequestSigner.Verify(identity.Key, canonical, RequestSigner.Sign(loaded.Key, canonical)));
    }

    [Fact]
    public void Missing_identity_returns_null()
    {
        using var dir = new TempDir();

        Assert.Null(AgentIdentity.TryLoad(dir.Paths()));
    }

    [Fact]
    public void Public_key_is_spki_p256_as_the_manager_expects()
    {
        using var identity = AgentIdentity.Generate();
        using var imported = ECDsa.Create();
        imported.ImportSubjectPublicKeyInfo(Convert.FromBase64String(identity.PublicKeyBase64), out _);

        Assert.Equal("nistP256", imported.ExportParameters(false).Curve.Oid.FriendlyName, ignoreCase: true);
    }

    [Fact]
    public void Settings_never_contain_secrets_and_server_config_respects_minimums()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        var store = new AgentSettingsStore(paths);
        var settings = new AgentSettings { ServerUrl = "https://m.local/" };
        settings.ApplyServerConfig(new ServerConfig(HeartbeatSeconds: 1, MetricsSeconds: 2, InventoryHours: 0, MaxPayloadBytes: 1));
        store.Save(settings);

        var json = File.ReadAllText(paths.ConfigFile);
        Assert.DoesNotContain("token", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("key", json, StringComparison.OrdinalIgnoreCase);

        var loaded = store.Load();
        Assert.Equal(AgentSettings.MinHeartbeatSeconds, loaded.HeartbeatSeconds);
        Assert.Equal(AgentSettings.MinMetricsSeconds, loaded.MetricsSeconds);
        Assert.Equal(AgentSettings.MinInventoryHours, loaded.InventoryHours);
    }

    [Fact]
    public void State_persists_and_revocation_survives_restart()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        var first = new AgentRuntime(new AgentStateStore(paths));
        Assert.False(first.IsEnrolled);

        first.SetEnrolled(new AgentState { AgentId = "a", EndpointId = "b" });
        first.MarkRevoked();

        var second = new AgentRuntime(new AgentStateStore(paths));
        Assert.True(second.IsEnrolled);
        Assert.True(second.IsRevoked);
    }
}

public class LoggingTests
{
    [Theory]
    [InlineData("enroll com tk_AbCdEf123456_-xyz agora", "tk_[redacted]")]
    [InlineData("X-Signature: MEUCIQDabc", "X-Signature: [redacted]")]
    [InlineData("password=hunter2 fim", "password=[redacted]")]
    [InlineData("Authorization: Bearer abc.def", "Authorization: [redacted]")]
    public void Secrets_are_masked(string input, string expectedFragment)
    {
        var output = LogRedactor.Redact(input);

        Assert.Contains(expectedFragment, output);
        Assert.DoesNotContain("hunter2", output);
        Assert.DoesNotContain("AbCdEf123456", output);
        Assert.DoesNotContain("MEUCIQDabc", output);
    }

    [Fact]
    public void Long_base64_blobs_are_masked_but_normal_text_is_not()
    {
        var blob = Convert.ToBase64String(RandomNumberGenerator.GetBytes(120));

        Assert.Contains("[redacted-blob]", LogRedactor.Redact("chave " + blob));
        Assert.Equal("Heartbeat ok em 12 ms", LogRedactor.Redact("Heartbeat ok em 12 ms"));
    }

    [Fact]
    public void File_logger_writes_redacted_lines_and_purges_old_files()
    {
        using var dir = new TempDir();
        var logs = Path.Combine(dir.Path, "logs");
        Directory.CreateDirectory(logs);
        var old = Path.Combine(logs, "agent-20200101.log");
        File.WriteAllText(old, "antigo");
        File.SetLastWriteTimeUtc(old, DateTime.UtcNow.AddDays(-30));

        using (var provider = new FileLoggerProvider(logs, retentionDays: 14))
        {
            provider.CreateLogger("t").LogWarning("falhou com tk_SEGREDO123456 e {Detail}", "ok");
        }

        Assert.False(File.Exists(old));
        var today = Directory.GetFiles(logs, "agent-*.log").Single();
        var text = File.ReadAllText(today);
        Assert.Contains("WARNING", text);
        Assert.Contains("tk_[redacted]", text);
        Assert.DoesNotContain("SEGREDO123456", text);
    }

    [Fact]
    public void File_logger_stops_at_the_size_cap()
    {
        using var dir = new TempDir();
        using var provider = new FileLoggerProvider(dir.Path, 14, maxFileBytes: 400);
        var logger = provider.CreateLogger("t");
        for (var i = 0; i < 50; i++)
        {
            logger.LogInformation("linha {I} {Pad}", i, new string('x', 40));
        }

        var file = Directory.GetFiles(dir.Path, "agent-*.log").Single();
        Assert.True(new FileInfo(file).Length < 1200);
        Assert.Contains("limite de tamanho", File.ReadAllText(file));
    }
}

public class AgentPathsTests
{
    [Fact]
    public void Restricting_the_acl_never_locks_the_current_user_out()
    {
        // Regressão: o `enroll` já trancou um usuário comum fora do próprio diretório (ACL só para SYSTEM/Administradores).
        using var dir = new TempDir();
        var paths = new AgentPaths(Path.Combine(dir.Path, "data"));

        var applied = paths.EnsureCreated(restrictAcl: true);

        paths.AssertWritable();
        File.WriteAllText(paths.ConfigFile, "{}");
        Assert.Equal(AgentPaths.IsElevated(), applied); // sem privilégio, a ACL NÃO é aplicada
        _ = Directory.GetFiles(paths.Root);              // ainda consegue listar
    }

    [Fact]
    public void Unwritable_directory_is_detected_before_any_token_is_spent()
    {
        using var dir = new TempDir();
        var blocker = Path.Combine(dir.Path, "arquivo");
        File.WriteAllText(blocker, "x");
        var paths = new AgentPaths(blocker); // um ARQUIVO no lugar do diretório de dados

        Assert.ThrowsAny<IOException>(() => paths.EnsureCreated(false));
    }
}
