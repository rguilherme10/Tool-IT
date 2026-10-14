using System.Net;
using System.Security.Cryptography;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Host;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Queue;
using AgentToolit.Core.Services;
using AgentToolit.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;

namespace AgentToolit.Tests;

public class ManagerClientTests
{
    private const string HeartbeatOk = """{"server_time":"2026-10-14T12:00:00+00:00","config_version":1,"tasks_available":false,"cancel_task_ids":[],"update_available":null}""";

    private static HeartbeatRequest Hb() => new(10, "running", "0.1.0", null, ["10.0.0.5"], 0);

    [Fact]
    public async Task Signed_request_carries_all_headers_and_signature_verifies_over_the_exact_bytes_sent()
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, HeartbeatOk));

        await client.HeartbeatAsync(Hb(), default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("POST", sent.Method);
        Assert.Equal("/api/agent/v1/heartbeat", sent.Path);
        Assert.Equal("11111111-2222-4333-8444-555555555555", sent.Headers["X-Agent-Id"]);
        Assert.Equal("0.1.0", sent.Headers["X-Agent-Version"]);

        var canonical = RequestSigner.Canonical("POST", sent.Path, "", sent.Headers["X-Timestamp"], sent.Headers["X-Nonce"], sent.Body);
        Assert.True(RequestSigner.Verify(identity.Key, canonical, sent.Headers["X-Signature"]));
        Assert.Contains("\"uptime_seconds\":10", sent.BodyText); // snake_case do contrato
        Assert.Contains("\"ip_addresses\":[\"10.0.0.5\"]", sent.BodyText);
        Assert.DoesNotContain("current_user", sent.BodyText);    // nulos omitidos
    }

    [Fact]
    public async Task Every_attempt_uses_a_fresh_nonce()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, HeartbeatOk));

        for (var i = 0; i < 5; i++)
        {
            await client.HeartbeatAsync(Hb(), default);
        }

        Assert.Equal(5, handler.Requests.Select(r => r.Headers["X-Nonce"]).Distinct().Count());
    }

    [Fact]
    public async Task Enroll_and_meta_are_not_signed()
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, r => r.Path.EndsWith("meta")
            ? StubHandler.Json(HttpStatusCode.OK, """{"protocol":"v1","min_agent_version":"0.1.0","server_time":"2026-10-14T12:00:00+00:00"}""")
            : StubHandler.Json(HttpStatusCode.Created, """{"agent_id":"a","endpoint_id":"e","server_time":"2026-10-14T12:00:00+00:00","config":{"heartbeat_seconds":60,"metrics_seconds":60,"inventory_hours":24,"max_payload_bytes":1048576}}"""));

        var meta = await client.GetMetaAsync(default);
        var enroll = await client.EnrollAsync(new EnrollRequest("tk_x", identity.PublicKeyBase64, "PC", new OsInfo("W", "1", "2", "x64"), "0.1.0", null), default);

        Assert.Equal("v1", meta.Protocol);
        Assert.Equal("a", enroll.AgentId);
        Assert.All(handler.Requests, r => Assert.False(r.Headers.ContainsKey("X-Signature")));
        Assert.Equal("GET", handler.Requests[0].Method);
        Assert.Contains("\"enrollment_token\":\"tk_x\"", handler.Requests[1].BodyText);
    }

    [Fact]
    public async Task Clock_skew_hint_corrects_the_timestamp_and_retries_once()
    {
        using var dir = new TempDir();
        var serverNow = DateTimeOffset.UtcNow.AddMinutes(-10);   // relógio local 10 min adiantado
        var (client, _, _, handler) = Enrolled.Create(dir, r =>
            Math.Abs(long.Parse(r.Headers["X-Timestamp"]) - serverNow.ToUnixTimeSeconds()) > 120
                ? StubHandler.Error(HttpStatusCode.Unauthorized, "auth.invalid", $$"""{"server_time":"{{serverNow:O}}"}""")
                : StubHandler.Json(HttpStatusCode.OK, HeartbeatOk));

        await client.HeartbeatAsync(Hb(), default);

        Assert.Equal(2, handler.Requests.Count);
        Assert.NotEqual(handler.Requests[0].Headers["X-Timestamp"], handler.Requests[1].Headers["X-Timestamp"]);
    }

    [Fact]
    public async Task Persistent_auth_failure_is_reported_not_looped()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Unauthorized, "auth.invalid"));

        await Assert.ThrowsAsync<ManagerAuthException>(() => client.HeartbeatAsync(Hb(), default));
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Revoked_response_maps_to_AgentRevokedException()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));

        await Assert.ThrowsAsync<AgentRevokedException>(() => client.HeartbeatAsync(Hb(), default));
    }

    [Fact]
    public async Task Revoked_runtime_never_touches_the_network()
    {
        using var dir = new TempDir();
        var (client, runtime, _, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, HeartbeatOk));
        runtime.MarkRevoked();

        await Assert.ThrowsAsync<AgentRevokedException>(() => client.HeartbeatAsync(Hb(), default));
        Assert.Empty(handler.Requests);
    }

    [Theory]
    [InlineData(HttpStatusCode.InternalServerError)]
    [InlineData(HttpStatusCode.BadGateway)]
    [InlineData(HttpStatusCode.ServiceUnavailable)]
    public async Task Server_errors_are_transient(HttpStatusCode status)
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(status, "<html>proxy</html>"));

        await Assert.ThrowsAsync<ManagerUnavailableException>(() => client.HeartbeatAsync(Hb(), default));
    }

    [Fact]
    public async Task Rate_limit_carries_retry_after()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ =>
        {
            var response = StubHandler.Error(HttpStatusCode.TooManyRequests, "rate.limited");
            response.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromSeconds(42));

            return response;
        });

        var ex = await Assert.ThrowsAsync<ManagerUnavailableException>(() => client.HeartbeatAsync(Hb(), default));
        Assert.Equal(TimeSpan.FromSeconds(42), ex.RetryAfter);
    }

    [Fact]
    public async Task Validation_errors_are_permanent_rejections_with_the_server_code()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Error((HttpStatusCode)422, "validation.failed"));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.HeartbeatAsync(Hb(), default));
        Assert.Equal(422, ex.Status);
        Assert.Equal("validation.failed", ex.Code);
    }

    [Fact]
    public async Task Network_failure_is_transient()
    {
        using var dir = new TempDir();
        var paths = dir.Paths();
        var runtime = new AgentRuntime(new AgentStateStore(paths));
        runtime.SetEnrolled(new AgentState { AgentId = "11111111-2222-4333-8444-555555555555" });
        using var identity = Core.Identity.AgentIdentity.Generate();
        var http = new HttpClient(new ThrowingHandler()) { BaseAddress = new Uri("https://manager.test/") };
        var client = new ManagerClient(http, identity, runtime, TimeProvider.System, "0.1.0");

        await Assert.ThrowsAsync<ManagerUnavailableException>(() => client.HeartbeatAsync(Hb(), default));
    }

    [Fact]
    public async Task Caller_cancellation_is_not_reported_as_a_network_failure()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, HeartbeatOk));
        using var cts = new CancellationTokenSource();
        await cts.CancelAsync();

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => client.HeartbeatAsync(Hb(), cts.Token));
    }

    private sealed class ThrowingHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) =>
            throw new HttpRequestException("sem rota");
    }
}

public class HeartbeatServiceTests
{
    private sealed class FakeHost : IHostFacts
    {
        public long UptimeSeconds => 123;

        public string? GetInteractiveUser() => "EMPRESA\\maria";

        public IReadOnlyList<string> GetIpAddresses() => ["10.0.0.5"];
    }

    private static (HeartbeatService Service, AgentRuntime Runtime, StubHandler Handler, OutboundQueue Queue) Build(
        TempDir dir, Func<CapturedRequest, HttpResponseMessage> respond, bool enrolled = true)
    {
        var (client, runtime, _, handler) = Enrolled.Create(dir, respond);
        if (!enrolled)
        {
            runtime = new AgentRuntime(new AgentStateStore(new AgentPaths(Path.Combine(dir.Path, "vazio"))));
            var other = Core.Identity.AgentIdentity.Generate();
            client = new ManagerClient(new HttpClient(handler) { BaseAddress = new Uri("https://m.test/") }, other, runtime, TimeProvider.System, "0.1.0");
        }

        var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), 100, 100000);
        var service = new HeartbeatService(client, runtime, new AgentSettings { HeartbeatSeconds = 60 }, new FakeHost(), queue,
            NullLogger<HeartbeatService>.Instance, new Backoff(random: new Random(1)));

        return (service, runtime, handler, queue);
    }

    private const string Ok = """{"server_time":"2026-10-14T12:00:00+00:00","config_version":1,"tasks_available":false,"cancel_task_ids":[],"update_available":null}""";

    [Fact]
    public async Task Success_waits_the_configured_interval_and_reports_host_facts_and_pending_queue()
    {
        using var dir = new TempDir();
        var (service, _, handler, queue) = Build(dir, _ => StubHandler.Json(HttpStatusCode.OK, Ok));
        using var _q = queue;
        queue.Enqueue(OutboundQueue.Metrics, [1], 5);

        var delay = await service.TickAsync(default);

        Assert.Equal(TimeSpan.FromSeconds(60), delay);
        var body = JsonDocument.Parse(handler.Requests.Single().Body).RootElement;
        Assert.Equal(123, body.GetProperty("uptime_seconds").GetInt64());
        Assert.Equal("EMPRESA\\maria", body.GetProperty("current_user").GetString());
        Assert.Equal(1, body.GetProperty("pending_events").GetInt32());
        Assert.Equal("running", body.GetProperty("service_state").GetString());
    }

    [Fact]
    public async Task Unavailable_manager_backs_off_with_growing_delays_and_recovers()
    {
        using var dir = new TempDir();
        var healthy = false;
        var (service, _, _, queue) = Build(dir, _ => healthy ? StubHandler.Json(HttpStatusCode.OK, Ok) : StubHandler.Json(HttpStatusCode.ServiceUnavailable, "x"));
        using var _q = queue;

        var d1 = await service.TickAsync(default);
        var d2 = await service.TickAsync(default);
        var d3 = await service.TickAsync(default);
        Assert.True(d1 < d2 && d2 < d3, $"{d1} {d2} {d3}");
        Assert.InRange(d1.TotalSeconds, 4, 6);

        healthy = true;
        Assert.Equal(TimeSpan.FromSeconds(60), await service.TickAsync(default));
        healthy = false;
        Assert.InRange((await service.TickAsync(default)).TotalSeconds, 4, 6); // backoff reiniciou
    }

    [Fact]
    public async Task Not_enrolled_stays_idle_without_network()
    {
        using var dir = new TempDir();
        var (service, _, handler, queue) = Build(dir, _ => StubHandler.Json(HttpStatusCode.OK, Ok), enrolled: false);
        using var _q = queue;

        Assert.Equal(HeartbeatService.IdleWhenNotEnrolled, await service.TickAsync(default));
        Assert.Empty(handler.Requests);
    }

    [Fact]
    public async Task Revocation_is_persisted_and_stops_all_further_requests()
    {
        using var dir = new TempDir();
        var (service, runtime, handler, queue) = Build(dir, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));
        using var _q = queue;

        Assert.Equal(HeartbeatService.IdleWhenRevoked, await service.TickAsync(default));
        Assert.True(runtime.IsRevoked);
        Assert.True(new AgentStateStore(dir.Paths()).Load()!.Revoked, "sobrevive a reinício do serviço");

        var requests = handler.Requests.Count;
        await service.TickAsync(default);
        await service.TickAsync(default);
        Assert.Equal(requests, handler.Requests.Count);
    }

    [Fact]
    public async Task Rate_limit_retry_after_is_honored()
    {
        using var dir = new TempDir();
        var (service, _, _, queue) = Build(dir, _ =>
        {
            var r = StubHandler.Error(HttpStatusCode.TooManyRequests, "rate.limited");
            r.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromSeconds(90));

            return r;
        });
        using var _q = queue;

        Assert.Equal(TimeSpan.FromSeconds(90), await service.TickAsync(default));
    }
}

public class SenderServiceTests
{
    private static MetricSample Sample(string id) =>
        new(id, DateTimeOffset.UtcNow, 10, 1000, 500, 60, [new VolumeMetric("C:", 100, 50)], null);

    private static (SenderService Service, OutboundQueue Queue, AgentRuntime Runtime, StubHandler Handler) Build(
        TempDir dir, Func<CapturedRequest, HttpResponseMessage> respond, int batch = 120)
    {
        var (client, runtime, _, handler) = Enrolled.Create(dir, respond);
        var queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), 10000, 100_000_000);
        var service = new SenderService(client, runtime, new AgentSettings { MetricsBatchSize = batch }, queue,
            NullLogger<SenderService>.Instance, new Backoff(random: new Random(1)));

        return (service, queue, runtime, handler);
    }

    private static HttpResponseMessage Accepted(CapturedRequest r) =>
        StubHandler.Json(HttpStatusCode.Accepted, r.Path.EndsWith("metrics") ? """{"accepted":1,"duplicates":0,"rejected":0}""" : """{"accepted":true,"snapshot_id":"s"}""");

    [Fact]
    public async Task Drains_metrics_in_batches_and_empties_the_queue()
    {
        using var dir = new TempDir();
        var (service, queue, _, handler) = Build(dir, Accepted, batch: 10);
        using var _q = queue;
        for (var i = 0; i < 25; i++)
        {
            queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample(Guid.NewGuid().ToString())), 5);
        }

        await service.TickAsync(default);

        Assert.Equal(0, queue.Count());
        Assert.Equal([10, 10, 5], handler.Requests.Select(r => JsonDocument.Parse(r.Body).RootElement.GetProperty("samples").GetArrayLength()));
    }

    [Fact]
    public async Task Nothing_is_lost_while_the_manager_is_down_and_everything_is_delivered_after_it_returns()
    {
        using var dir = new TempDir();
        var up = false;
        var (service, queue, _, handler) = Build(dir, r => up ? Accepted(r) : StubHandler.Json(HttpStatusCode.ServiceUnavailable, "x"));
        using var _q = queue;
        for (var i = 0; i < 5; i++)
        {
            queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample("s" + i)), 5);
        }

        var delay = await service.TickAsync(default);
        Assert.Equal(5, queue.Count());
        Assert.True(delay >= SenderService.Interval, "falha nunca acelera as tentativas abaixo do intervalo normal");

        up = true;
        await service.TickAsync(default);
        Assert.Equal(0, queue.Count());
        var last = JsonDocument.Parse(handler.Requests.Last().Body).RootElement.GetProperty("samples");
        Assert.Equal(["s0", "s1", "s2", "s3", "s4"], last.EnumerateArray().Select(s => s.GetProperty("sample_id").GetString()));
    }

    [Fact]
    public async Task Rejected_batch_is_discarded_so_it_cannot_block_the_queue()
    {
        using var dir = new TempDir();
        var (service, queue, _, _) = Build(dir, _ => StubHandler.Error((HttpStatusCode)422, "validation.failed"));
        using var _q = queue;
        queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample("x")), 5);

        await service.TickAsync(default);

        Assert.Equal(0, queue.Count());
    }

    [Fact]
    public async Task Corrupt_queue_items_are_dropped_not_retried_forever()
    {
        using var dir = new TempDir();
        var (service, queue, _, handler) = Build(dir, Accepted);
        using var _q = queue;
        queue.Enqueue(OutboundQueue.Metrics, "isso não é json"u8.ToArray(), 5);
        queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample("ok")), 5);

        await service.TickAsync(default);

        Assert.Equal(0, queue.Count());
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Inventory_is_sent_once_and_removed_on_ack()
    {
        using var dir = new TempDir();
        var (service, queue, _, handler) = Build(dir, Accepted);
        using var _q = queue;
        var snapshot = new InventoryRequest("snap-1", DateTimeOffset.UtcNow, new Dictionary<string, SectionResult> { ["network"] = SectionResult.NotAvailable("access_denied") });
        queue.Enqueue(OutboundQueue.Inventory, Json.Serialize(snapshot), 10);

        await service.TickAsync(default);

        Assert.Equal(0, queue.Count());
        var body = JsonDocument.Parse(handler.Requests.Single().Body).RootElement;
        Assert.Equal("snap-1", body.GetProperty("snapshot_id").GetString());
        Assert.Equal("unavailable", body.GetProperty("sections").GetProperty("network").GetProperty("status").GetString());
        Assert.Equal("access_denied", body.GetProperty("sections").GetProperty("network").GetProperty("reason").GetString());
    }

    [Fact]
    public async Task Revocation_keeps_the_queue_and_stops_sending()
    {
        using var dir = new TempDir();
        var (service, queue, runtime, handler) = Build(dir, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));
        using var _q = queue;
        queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample("x")), 5);

        await service.TickAsync(default);
        await service.TickAsync(default);

        Assert.True(runtime.IsRevoked);
        Assert.Equal(1, queue.Count());
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Rate_limit_does_not_drop_items()
    {
        using var dir = new TempDir();
        var (service, queue, _, _) = Build(dir, _ => StubHandler.Error((HttpStatusCode)429, "rate.limited"));
        using var _q = queue;
        queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(Sample("x")), 5);

        await service.TickAsync(default);

        Assert.Equal(1, queue.Count());
    }
}

public class ContractSerializationTests
{
    [Fact]
    public void Enroll_request_matches_the_documented_wire_format()
    {
        var json = JsonDocument.Parse(Json.Serialize(new EnrollRequest(
            "tk_abc", "AAAA", "PC-001", new OsInfo("Windows 11 Pro", "10.0.26100", "26100", "x64"), "0.1.0", new string('a', 64)))).RootElement;

        Assert.Equal("tk_abc", json.GetProperty("enrollment_token").GetString());
        Assert.Equal("AAAA", json.GetProperty("public_key").GetString());
        Assert.Equal("x64", json.GetProperty("os").GetProperty("arch").GetString());
        Assert.Equal("0.1.0", json.GetProperty("agent_version").GetString());
        Assert.True(json.TryGetProperty("machine_fingerprint", out _));
    }

    [Fact]
    public void Metric_sample_uses_snake_case_and_omits_null_temperature()
    {
        var json = Json.Serialize(new MetricSample("id", new DateTimeOffset(2026, 10, 14, 12, 0, 0, TimeSpan.Zero), 12.5, 16384, 8000, 3600,
            [new VolumeMetric("C:", 475.9, 210.4)], null));
        var text = System.Text.Encoding.UTF8.GetString(json);

        Assert.Contains("\"sample_id\":\"id\"", text);
        Assert.Contains("\"at\":\"2026-10-14T12:00:00+00:00\"", text);
        Assert.Contains("\"cpu_percent\":12.5", text);
        Assert.Contains("\"mem_available_mb\":8000", text);
        Assert.Contains("\"volumes\":[{\"mount\":\"C:\",\"total_gb\":475.9,\"free_gb\":210.4}]", text);
        Assert.DoesNotContain("temperature_c", text);
    }

    [Fact]
    public void Heartbeat_response_parses_the_manager_payload_including_unknown_fields()
    {
        var response = Json.Deserialize<HeartbeatResponse>(
            """{"server_time":"2026-10-14T12:00:00+00:00","config_version":3,"tasks_available":true,"cancel_task_ids":["a"],"update_available":{"version":"9.9.9"},"campo_futuro":1}"""u8);

        Assert.Equal(3, response!.ConfigVersion);
        Assert.True(response.TasksAvailable);
        Assert.Equal(["a"], response.CancelTaskIds);
        Assert.Equal("9.9.9", response.UpdateAvailable!.Value.GetProperty("version").GetString());
    }

    [Fact]
    public void Metrics_ack_tolerates_a_manager_without_the_rejected_field()
    {
        var ack = Json.Deserialize<MetricsAck>("""{"accepted":3,"duplicates":1}"""u8);

        Assert.Equal(new MetricsAck(3, 1, 0), ack);
    }

    [Fact]
    public void Section_results_serialize_status_reason_and_data()
    {
        var text = System.Text.Encoding.UTF8.GetString(Json.Serialize(new Dictionary<string, SectionResult>
        {
            ["hardware"] = SectionResult.Success(new { memory_total_mb = 16384 }),
            ["peripherals"] = SectionResult.NotAvailable("access_denied"),
            ["software"] = SectionResult.Failed("TimeoutException"),
        }));

        Assert.Contains("\"hardware\":{\"status\":\"ok\",\"data\":{\"memory_total_mb\":16384}}", text);
        Assert.Contains("\"peripherals\":{\"status\":\"unavailable\",\"reason\":\"access_denied\"}", text);
        Assert.Contains("\"software\":{\"status\":\"error\",\"reason\":\"TimeoutException\"}", text);
    }
}
