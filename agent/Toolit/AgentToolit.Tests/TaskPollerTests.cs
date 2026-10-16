using System.Net;
using System.Text.Json;
using AgentToolit.Core.Config;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Execution.Handlers;
using AgentToolit.Core.Host;
using AgentToolit.Core.Net;
using AgentToolit.Core.Queue;
using AgentToolit.Core.Services;
using AgentToolit.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;
using static AgentToolit.Tests.TaskExecutionTests;

namespace AgentToolit.Tests;

public class TaskPollerTests
{
    private const string TaskId = "6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80";
    private const string AttemptId = "0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10";

    private static string NextJson(string type = "system.info", string paramsJson = "{}") =>
        "{\"task_id\":\"" + TaskId + "\",\"attempt_id\":\"" + AttemptId + "\",\"type\":\"" + type + "\",\"params\":" + paramsJson +
        ",\"timeout_seconds\":30,\"lease_expires_at\":\"2026-10-15T12:01:30+00:00\",\"risk\":\"low\"}";

    private const string ResultOk = "{\"task_id\":\"" + TaskId + "\",\"state\":\"completed\"}";

    private sealed class FakeRestarter : IAgentRestarter
    {
        public Action? OnRestart;

        public int Calls;

        public void RequestRestart()
        {
            Calls++;
            OnRestart?.Invoke();
        }
    }

    private sealed record Rig(TaskPoller Poller, AgentRuntime Runtime, StubHandler Handler, SpyHandler Spy, FakeRestarter Restarter, TaskCoordinator Coordinator);

    private static Rig Build(TempDir dir, Func<CapturedRequest, HttpResponseMessage> respond, params ITaskHandler[] extra)
    {
        var (client, runtime, _, handler) = Enrolled.Create(dir, respond);
        var spy = new SpyHandler("system.info");
        var coordinator = new TaskCoordinator();
        var runner = new TaskRunner([spy, .. extra], coordinator, TimeProvider.System, NullLogger<TaskRunner>.Instance);
        var restarter = new FakeRestarter();
        var poller = new TaskPoller(client, runner, runtime, new AgentSettings { TaskPollSeconds = 10 }, NullLogger<TaskPoller>.Instance,
            restarter, new Backoff(random: new Random(1)));

        return new Rig(poller, runtime, handler, spy, restarter, coordinator);
    }

    private static bool IsNext(CapturedRequest r) => r.Method == "GET" && r.Path.EndsWith("/tasks/next");

    private static bool IsResult(CapturedRequest r) => r.Method == "POST" && r.Path == "/api/agent/v1/tasks/" + TaskId + "/result";

    [Fact]
    public async Task Poller_idle_when_not_enrolled_or_revoked()
    {
        using var dir = new TempDir();
        var rig = Build(dir, _ => StubHandler.Json(HttpStatusCode.OK, NextJson()));

        // Não registrado: ocioso como o heartbeat e sem nenhuma chamada.
        var (client, _, _, _) = Enrolled.Create(dir, _ => throw new InvalidOperationException("não deveria chamar"));
        var empty = new AgentRuntime(new AgentStateStore(new AgentPaths(Path.Combine(dir.Path, "vazio"))));
        var notEnrolled = new TaskPoller(client, new TaskRunner([], new TaskCoordinator(), TimeProvider.System, NullLogger<TaskRunner>.Instance),
            empty, new AgentSettings(), NullLogger<TaskPoller>.Instance);
        Assert.Equal(HeartbeatService.IdleWhenNotEnrolled, await notEnrolled.TickAsync(default));

        rig.Runtime.MarkRevoked();
        Assert.Equal(HeartbeatService.IdleWhenRevoked, await rig.Poller.TickAsync(default));
        Assert.Empty(rig.Handler.Requests);
        Assert.Equal(0, rig.Spy.Calls);

        // 403 agent.revoked na consulta: marca revogado e fica ocioso.
        using var dir2 = new TempDir();
        var revoked = Build(dir2, _ => StubHandler.Error(HttpStatusCode.Forbidden, "agent.revoked"));
        Assert.Equal(HeartbeatService.IdleWhenRevoked, await revoked.Poller.TickAsync(default));
        Assert.True(revoked.Runtime.IsRevoked);
    }

    [Fact]
    public async Task Poller_runs_task_then_returns_zero_delay()
    {
        using var dir = new TempDir();
        var served = false;
        var rig = Build(dir, r =>
        {
            if (IsNext(r))
            {
                if (served)
                {
                    return new HttpResponseMessage(HttpStatusCode.NoContent);
                }

                served = true;

                return StubHandler.Json(HttpStatusCode.OK, NextJson());
            }

            return IsResult(r) ? StubHandler.Json(HttpStatusCode.OK, ResultOk) : StubHandler.Error(HttpStatusCode.NotFound, "request.not_found");
        });

        Assert.Equal(TimeSpan.Zero, await rig.Poller.TickAsync(default));
        Assert.Equal(1, rig.Spy.Calls);

        var post = Assert.Single(rig.Handler.Requests, IsResult);
        var body = JsonDocument.Parse(post.Body).RootElement;
        Assert.Equal(AttemptId, body.GetProperty("attempt_id").GetString());
        Assert.Equal("completed", body.GetProperty("status").GetString());
        Assert.Equal("ok", body.GetProperty("stdout").GetString());
        Assert.False(body.GetProperty("truncated").GetBoolean());

        // Sem tarefa: espera o intervalo de poll.
        Assert.Equal(TimeSpan.FromSeconds(10), await rig.Poller.TickAsync(default));
        Assert.Equal(1, rig.Spy.Calls);

        // Tipo desconhecido vindo do Manager: rejected enviado, nada executado.
        using var dir2 = new TempDir();
        var hostile = Build(dir2, r => IsNext(r) ? StubHandler.Json(HttpStatusCode.OK, NextJson("shell.exec", """{"cmd":"del"}""")) : StubHandler.Json(HttpStatusCode.OK, ResultOk));
        Assert.Equal(TimeSpan.Zero, await hostile.Poller.TickAsync(default));
        var rejected = JsonDocument.Parse(Assert.Single(hostile.Handler.Requests, IsResult).Body).RootElement;
        Assert.Equal("rejected", rejected.GetProperty("status").GetString());
        Assert.Equal(0, hostile.Spy.Calls);
    }

    [Fact]
    public async Task Poller_posts_result_and_drops_it_on_409()
    {
        using var dir = new TempDir();
        var tasks = 0;
        var rig = Build(dir, r =>
        {
            if (IsNext(r))
            {
                return tasks++ == 0 ? StubHandler.Json(HttpStatusCode.OK, NextJson()) : new HttpResponseMessage(HttpStatusCode.NoContent);
            }

            return StubHandler.Error(HttpStatusCode.Conflict, "task.conflict");
        });

        Assert.Equal(TimeSpan.Zero, await rig.Poller.TickAsync(default));
        Assert.Single(rig.Handler.Requests, IsResult);

        // Descartado: o próximo ciclo não reenvia.
        Assert.Equal(TimeSpan.FromSeconds(10), await rig.Poller.TickAsync(default));
        Assert.Single(rig.Handler.Requests, IsResult);
    }

    [Fact]
    public async Task Poller_retries_result_after_network_failure_without_rerunning()
    {
        using var dir = new TempDir();
        var managerUp = false;
        var tasks = 0;
        var rig = Build(dir, r =>
        {
            if (IsNext(r))
            {
                return tasks++ == 0 ? StubHandler.Json(HttpStatusCode.OK, NextJson()) : new HttpResponseMessage(HttpStatusCode.NoContent);
            }

            return managerUp ? StubHandler.Json(HttpStatusCode.OK, ResultOk) : StubHandler.Json(HttpStatusCode.ServiceUnavailable, "x");
        });

        var delay = await rig.Poller.TickAsync(default);
        Assert.InRange(delay.TotalSeconds, 4, 6); // backoff
        Assert.Equal(1, rig.Spy.Calls);

        managerUp = true;
        Assert.Equal(TimeSpan.Zero, await rig.Poller.TickAsync(default)); // reenviou o resultado pendente
        Assert.Equal(2, rig.Handler.Requests.Count(IsResult));
        Assert.Equal(1, rig.Spy.Calls); // não executou de novo
        Assert.Equal(1, rig.Handler.Requests.Count(IsNext));

        Assert.Equal(TimeSpan.FromSeconds(10), await rig.Poller.TickAsync(default));
        Assert.Equal(2, rig.Handler.Requests.Count(IsResult));
    }

    [Fact]
    public async Task Agent_restart_sends_result_before_stopping_host()
    {
        using var dir = new TempDir();
        var rig = Build(dir, r => IsNext(r) ? StubHandler.Json(HttpStatusCode.OK, NextJson("agent.restart")) : StubHandler.Json(HttpStatusCode.OK, ResultOk),
            new AgentRestartHandler());
        var resultsAtRestart = -1;
        rig.Restarter.OnRestart = () => resultsAtRestart = rig.Handler.Requests.Count(IsResult);

        Assert.Equal(TimeSpan.Zero, await rig.Poller.TickAsync(default));

        Assert.Equal(1, rig.Restarter.Calls);
        Assert.Equal(1, resultsAtRestart); // resultado enviado ANTES de pedir a parada
        var body = JsonDocument.Parse(Assert.Single(rig.Handler.Requests, IsResult).Body).RootElement;
        Assert.Equal("completed", body.GetProperty("status").GetString());

        // Com o resultado recusado (409) o reinício pedido ainda acontece.
        using var dir2 = new TempDir();
        var conflict = Build(dir2, r => IsNext(r) ? StubHandler.Json(HttpStatusCode.OK, NextJson("agent.restart")) : StubHandler.Error(HttpStatusCode.Conflict, "task.conflict"),
            new AgentRestartHandler());
        await conflict.Poller.TickAsync(default);
        Assert.Equal(1, conflict.Restarter.Calls);
    }

    private sealed class FakeHost : IHostFacts
    {
        public long UptimeSeconds => 1;

        public string? GetInteractiveUser() => null;

        public IReadOnlyList<string> GetIpAddresses() => [];
    }

    private static HeartbeatService Heartbeat(TempDir dir, string responseJson, TaskCoordinator coordinator, out OutboundQueue queue)
    {
        var (client, runtime, _, _) = Enrolled.Create(dir, _ => StubHandler.Json(HttpStatusCode.OK, responseJson));
        queue = new OutboundQueue(Path.Combine(dir.Path, "q.db"), 100, 100000);

        return new HeartbeatService(client, runtime, new AgentSettings(), new FakeHost(), queue, NullLogger<HeartbeatService>.Instance, null, coordinator);
    }

    [Fact]
    public async Task Heartbeat_tasks_available_wakes_poller()
    {
        using var dir = new TempDir();
        var coordinator = new TaskCoordinator();

        // Sem sinal: a espera respeita o intervalo.
        var quiet = coordinator.WaitAsync(TimeSpan.FromMilliseconds(200), default);
        await quiet.WaitAsync(TimeSpan.FromSeconds(10));

        var waiting = coordinator.WaitAsync(TimeSpan.FromMinutes(10), default);
        Assert.False(waiting.IsCompleted);

        var service = Heartbeat(dir, """{"server_time":"2026-10-15T12:00:00+00:00","config_version":1,"tasks_available":true,"cancel_task_ids":[],"update_available":null}""", coordinator, out var queue);
        using var _q = queue;
        await service.TickAsync(default);

        await waiting.WaitAsync(TimeSpan.FromSeconds(10)); // acordou bem antes dos 10 minutos

        // Sinal recebido sem ninguém esperando fica guardado para a próxima espera (uma vez).
        coordinator.Notify();
        await coordinator.WaitAsync(TimeSpan.FromMinutes(10), default).WaitAsync(TimeSpan.FromSeconds(10));
        var second = coordinator.WaitAsync(TimeSpan.FromMinutes(10), default);
        Assert.NotSame(second, await Task.WhenAny(second, Task.Delay(200)));

        // Cancelamento do host interrompe a espera.
        using var cts = new CancellationTokenSource();
        var stopping = coordinator.WaitAsync(TimeSpan.FromMinutes(10), cts.Token);
        cts.Cancel();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => stopping.WaitAsync(TimeSpan.FromSeconds(10)));
    }

    [Fact]
    public async Task Heartbeat_cancel_ids_cancel_running_task()
    {
        using var dir = new TempDir();
        var coordinator = new TaskCoordinator();
        var slow = new SpyHandler("system.info", async (_, ct) =>
        {
            await Task.Delay(Timeout.Infinite, ct);

            return TaskOutcome.Completed("nunca");
        });
        var runner = new TaskRunner([slow], coordinator, TimeProvider.System, NullLogger<TaskRunner>.Instance);
        var running = runner.RunAsync(Assignment("system.info", taskId: TaskId), default);
        await slow.Started.Task.WaitAsync(TimeSpan.FromSeconds(10));

        var service = Heartbeat(dir, "{\"server_time\":\"2026-10-15T12:00:00+00:00\",\"config_version\":1,\"tasks_available\":false,\"cancel_task_ids\":[\"" + TaskId + "\"],\"update_available\":null}", coordinator, out var queue);
        using var _q = queue;
        await service.TickAsync(default);

        var result = await running.WaitAsync(TimeSpan.FromSeconds(10));
        Assert.Equal("cancelled", result.Status);
    }
}
