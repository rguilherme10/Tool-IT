using System.Net;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Tests.Support;

namespace AgentToolit.Tests;

public class TaskClientTests
{
    /// <summary>Exemplos compartilhados com o Manager (tests/contracts), localizados como o vetor de assinatura.</summary>
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

    private static TaskResultRequest Result() => new(
        "0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10", "completed", 0, "Serviço Spooler reiniciado.", "", false,
        DateTimeOffset.Parse("2026-10-15T12:00:05+00:00"), DateTimeOffset.Parse("2026-10-15T12:00:07+00:00"), 1200, null);

    private static HeartbeatRequest Hb() => new(1, "running", "0.1.0", null, null, 0);

    [Fact]
    public async Task Next_task_signs_the_query_and_parses_assignment()
    {
        using var dir = new TempDir();
        var json = Contract("task-next.json");
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, json));

        var task = await client.NextTaskAsync(default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("GET", sent.Method);
        Assert.Equal("/api/agent/v1/tasks/next", sent.Path);   // o path assinado não leva a query
        Assert.Equal("?wait=0", sent.Query);

        var ts = sent.Headers["X-Timestamp"];
        var nonce = sent.Headers["X-Nonce"];
        var signature = sent.Headers["X-Signature"];
        Assert.True(RequestSigner.Verify(identity.Key, RequestSigner.Canonical("GET", sent.Path, "wait=0", ts, nonce, sent.Body), signature));
        Assert.False(RequestSigner.Verify(identity.Key, RequestSigner.Canonical("GET", sent.Path, "", ts, nonce, sent.Body), signature));
        Assert.False(RequestSigner.Verify(identity.Key, RequestSigner.Canonical("GET", sent.Path, "wait=30", ts, nonce, sent.Body), signature));

        Assert.NotNull(task);
        Assert.Equal("6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80", task.TaskId);
        Assert.Equal("0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10", task.AttemptId);
        Assert.Equal("service.restart", task.Type);
        Assert.Equal(JsonValueKind.Object, task.Params.ValueKind);
        Assert.Equal("Spooler", task.Params.GetProperty("name").GetString());
        Assert.Equal(60, task.TimeoutSeconds);
        Assert.Equal(DateTimeOffset.Parse("2026-10-15T12:01:30+00:00"), task.LeaseExpiresAt);
        Assert.Equal("medium", task.Risk);

        // Os sete campos do contrato, nem mais nem menos, ao reserializar.
        using var again = JsonDocument.Parse(Json.Serialize(task));
        using var expected = JsonDocument.Parse(json);
        Assert.Equal(
            expected.RootElement.EnumerateObject().Select(p => p.Name).Order(),
            again.RootElement.EnumerateObject().Select(p => p.Name).Order());
    }

    [Fact]
    public async Task Next_task_returns_null_on_204()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => new HttpResponseMessage(HttpStatusCode.NoContent));

        Assert.Null(await client.NextTaskAsync(default));
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task Other_calls_still_fail_on_an_empty_success_body()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => new HttpResponseMessage(HttpStatusCode.NoContent));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.HeartbeatAsync(Hb(), default));
        Assert.Equal("response.empty", ex.Code);
    }

    [Fact]
    public async Task Next_task_maps_revocation_and_server_errors_like_other_calls()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));
        await Assert.ThrowsAsync<AgentRevokedException>(() => client.NextTaskAsync(default));

        using var dir2 = new TempDir();
        var (client2, _, _, _) = Enrolled.Create(dir2, _ => new HttpResponseMessage(HttpStatusCode.BadGateway));
        await Assert.ThrowsAsync<ManagerUnavailableException>(() => client2.NextTaskAsync(default));
    }

    [Fact]
    public async Task Result_is_posted_signed_to_the_task_route_and_returns_true()
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));

        Assert.True(await client.SendResultAsync("6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80", Result(), default));

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("POST", sent.Method);
        Assert.Equal("/api/agent/v1/tasks/6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80/result", sent.Path);
        var canonical = RequestSigner.Canonical("POST", sent.Path, "", sent.Headers["X-Timestamp"], sent.Headers["X-Nonce"], sent.Body);
        Assert.True(RequestSigner.Verify(identity.Key, canonical, sent.Headers["X-Signature"]));
    }

    [Fact]
    public async Task Result_conflict_409_returns_false()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Conflict, "task.conflict"));

        Assert.False(await client.SendResultAsync("t1", Result(), default));
        Assert.Single(handler.Requests);   // sem retentativa
    }

    [Fact]
    public async Task Result_other_errors_still_throw()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Conflict, "something.else"));
        await Assert.ThrowsAsync<ManagerRejectedException>(() => client.SendResultAsync("t1", Result(), default));

        using var dir2 = new TempDir();
        var (client2, _, _, _) = Enrolled.Create(dir2, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));
        await Assert.ThrowsAsync<AgentRevokedException>(() => client2.SendResultAsync("t1", Result(), default));

        using var dir3 = new TempDir();
        var (client3, _, _, _) = Enrolled.Create(dir3, _ => new HttpResponseMessage(HttpStatusCode.InternalServerError));
        await Assert.ThrowsAsync<ManagerUnavailableException>(() => client3.SendResultAsync("t1", Result(), default));

        using var dir4 = new TempDir();
        var (client4, _, _, _) = Enrolled.Create(dir4, _ => StubHandler.Error(HttpStatusCode.NotFound, "task.not_found"));
        await Assert.ThrowsAsync<ManagerRejectedException>(() => client4.SendResultAsync("t1", Result(), default));
    }

    [Fact]
    public async Task Result_serializes_snake_case_and_omits_nulls()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));

        await client.SendResultAsync("t1", Result(), default);

        using var sent = JsonDocument.Parse(Assert.Single(handler.Requests).Body);
        using var contract = JsonDocument.Parse(Contract("task-result.json"));

        // O exemplo do contrato traz "error": null; o Agent omite nulos, o Manager trata ausente como nulo.
        var expected = contract.RootElement.EnumerateObject().Where(p => p.Value.ValueKind != JsonValueKind.Null).ToList();
        Assert.Equal(expected.Select(p => p.Name).Order(), sent.RootElement.EnumerateObject().Select(p => p.Name).Order());
        Assert.False(sent.RootElement.TryGetProperty("error", out _));

        foreach (var p in expected)
        {
            Assert.Equal(p.Value.ToString(), sent.RootElement.GetProperty(p.Name).ToString());
        }
    }

    [Fact]
    public async Task Result_with_error_and_no_exit_code_keeps_error_and_omits_exit_code()
    {
        using var dir = new TempDir();
        var (client, _, _, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));
        var r = Result() with { Status = "rejected", ExitCode = null, Stdout = null, Stderr = null, Error = "tipo desconhecido" };

        await client.SendResultAsync("t1", r, default);

        using var sent = JsonDocument.Parse(Assert.Single(handler.Requests).Body);
        Assert.Equal("tipo desconhecido", sent.RootElement.GetProperty("error").GetString());
        Assert.False(sent.RootElement.TryGetProperty("exit_code", out _));
        Assert.False(sent.RootElement.TryGetProperty("stdout", out _));
    }

    [Fact]
    public async Task Progress_is_posted_signed_with_attempt_and_percent()
    {
        using var dir = new TempDir();
        var (client, _, identity, handler) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, "{}"));

        await client.ProgressAsync("t1", new TaskProgressRequest("a1", 40), default);

        var sent = Assert.Single(handler.Requests);
        Assert.Equal("/api/agent/v1/tasks/t1/progress", sent.Path);
        Assert.Equal("{\"attempt_id\":\"a1\",\"percent\":40}", sent.BodyText);
        var canonical = RequestSigner.Canonical("POST", sent.Path, "", sent.Headers["X-Timestamp"], sent.Headers["X-Nonce"], sent.Body);
        Assert.True(RequestSigner.Verify(identity.Key, canonical, sent.Headers["X-Signature"]));
    }

    [Fact]
    public async Task Progress_conflict_surfaces_as_rejected_exception()
    {
        using var dir = new TempDir();
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Error(HttpStatusCode.Conflict, "task.conflict"));

        var ex = await Assert.ThrowsAsync<ManagerRejectedException>(() => client.ProgressAsync("t1", new TaskProgressRequest("a1", 10), default));
        Assert.Equal("task.conflict", ex.Code);
    }

    [Fact]
    public async Task Heartbeat_response_with_cancel_ids_is_parsed()
    {
        using var dir = new TempDir();
        const string body = "{\"server_time\":\"2026-10-15T12:00:00+00:00\",\"config_version\":1,\"tasks_available\":true,\"cancel_task_ids\":[\"t1\",\"t2\"],\"update_available\":null}";
        var (client, _, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, body));

        var response = await client.HeartbeatAsync(Hb(), default);

        Assert.True(response.TasksAvailable);
        Assert.Equal(["t1", "t2"], response.CancelTaskIds);
    }

    [Fact]
    public void Task_poll_interval_defaults_to_ten_seconds_and_is_bounded()
    {
        Assert.Equal(10, new AgentSettings().TaskPollSeconds);
        Assert.Equal(AgentSettings.MinTaskPollSeconds, new AgentSettings { TaskPollSeconds = 1 }.TaskPollSeconds);
        Assert.Equal(AgentSettings.MaxTaskPollSeconds, new AgentSettings { TaskPollSeconds = 9999 }.TaskPollSeconds);
        Assert.Equal(30, new AgentSettings { TaskPollSeconds = 30 }.TaskPollSeconds);
    }
}
