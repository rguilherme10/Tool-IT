using System.Text;
using System.Text.Json;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Execution.Handlers;
using AgentToolit.Core.Protocol;
using Microsoft.Extensions.Logging.Abstractions;

namespace AgentToolit.Tests;

/// <summary>Validador, catálogo local, processos protegidos e o runner (sem tocar no sistema: só fakes).</summary>
public class TaskExecutionTests
{
    internal static JsonElement P(string json) => JsonDocument.Parse(json).RootElement.Clone();

    internal static TaskAssignment Assignment(string type, string paramsJson = "{}", int timeoutSeconds = 30, string taskId = "6f1c1d0e-8b7a-4c55-9a3e-2d4f5a6b7c80") =>
        new(taskId, "0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10", type, P(paramsJson), timeoutSeconds, DateTimeOffset.UtcNow.AddMinutes(1), "low");

    internal static TaskRunner Runner(TaskCoordinator? coordinator = null, params ITaskHandler[] handlers) =>
        new(handlers, coordinator ?? new TaskCoordinator(), TimeProvider.System, NullLogger<TaskRunner>.Instance);

    /// <summary>Handler espião: registra a chamada e devolve o que o teste mandar (ou espera o cancelamento).</summary>
    internal sealed class SpyHandler(string type, Func<JsonElement, CancellationToken, Task<TaskOutcome>>? run = null) : ITaskHandler
    {
        public string Type { get; } = type;

        public int Calls;

        public readonly TaskCompletionSource Started = new(TaskCreationOptions.RunContinuationsAsynchronously);

        public async Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
        {
            Interlocked.Increment(ref Calls);
            Started.TrySetResult();

            return run is null ? TaskOutcome.Completed("ok") : await run(parameters, ct);
        }
    }

    internal sealed class FakeProcessApi : IProcessApi
    {
        public Dictionary<int, ProcessInfo> Processes { get; } = [];

        public List<int> Killed { get; } = [];

        /// <summary>PID → crítico (true), não crítico (false) ou não verificável (null). Ausente = não crítico.</summary>
        public Dictionary<int, bool?> Critical { get; } = [];

        public bool? IsCritical(int pid) => Critical.TryGetValue(pid, out var c) ? c : false;

        public IReadOnlyList<ProcessInfo> List() => Processes.Values.ToList();

        public ProcessInfo? Get(int pid) => Processes.GetValueOrDefault(pid);

        public void Kill(int pid)
        {
            Killed.Add(pid);
            Processes.Remove(pid);
        }

        public FakeProcessApi With(int pid, string name, double workingSetMb = 10)
        {
            Processes[pid] = new ProcessInfo(pid, name, null, null, null, workingSetMb, null);

            return this;
        }
    }

    [Fact]
    public void Validator_rejects_hostile_names_and_pids()
    {
        // Válidos (mesmos do TaskCatalogTest do Manager).
        Assert.True(TaskSchemaValidator.Validate("service.start", P("""{"name":"Spooler"}""")).Ok);
        Assert.True(TaskSchemaValidator.Validate("service.stop", P("""{"name":"MSSQL$SQLEXPRESS"}""")).Ok);
        Assert.True(TaskSchemaValidator.Validate("process.kill", P("""{"pid":4321,"name":"notepad.exe"}""")).Ok);
        Assert.True(TaskSchemaValidator.Validate("process.kill", P("""{"pid":5,"name":"x"}""")).Ok);
        Assert.True(TaskSchemaValidator.Validate("process.list", P("{}")).Ok);
        Assert.True(TaskSchemaValidator.Validate("agent.restart", P("{}")).Ok);
        Assert.True(TaskSchemaValidator.Validate("process.kill", P("{\"pid\":7,\"name\":\"" + new string('é', 128) + "\"}")).Ok); // 128 caracteres, não bytes

        string[] hostileNames = ["a b", "a;b", "../x", new string('a', 257), "", "a|b", "a\nb", new string('a', 129), "Spooler\n", "RpcSs\n", "RpcSs\r\n", "Spooler\r", "Spo\u0000oler", "Spöoler"];
        foreach (var name in hostileNames)
        {
            var (ok, error) = TaskSchemaValidator.Validate("service.start", P("{\"name\":" + JsonSerializer.Serialize(name) + "}"));
            Assert.False(ok, "nome " + JsonSerializer.Serialize(name));
            Assert.False(string.IsNullOrEmpty(error));
        }

        foreach (var name in new[] { "123", "null", "[\"x\"]", "true", "{}" })
        {
            Assert.False(TaskSchemaValidator.Validate("service.start", P("{\"name\":" + name + "}")).Ok, "nome " + name);
        }

        Assert.False(TaskSchemaValidator.Validate("service.start", P("{}")).Ok, "sem name");

        foreach (var pid in new[] { "\"4 \"", "-1", "3", "\"1234\"", "12.0", "12.5", "null", "0", "4", "1e3", "99999999999", "true" })
        {
            Assert.False(TaskSchemaValidator.Validate("process.kill", P("{\"pid\":" + pid + ",\"name\":\"notepad.exe\"}")).Ok, "pid " + pid);
        }

        Assert.False(TaskSchemaValidator.Validate("process.kill", P("""{"pid":1234}""")).Ok, "sem name");
        Assert.False(TaskSchemaValidator.Validate("process.kill", P("""{"pid":1234,"name":""}""")).Ok, "name vazio");
        Assert.False(TaskSchemaValidator.Validate("process.kill", P("{\"pid\":1234,\"name\":\"" + new string('a', 129) + "\"}")).Ok, "name longo");
        Assert.False(TaskSchemaValidator.Validate("process.kill", P("""{"pid":1234,"name":"a\u0007b"}""")).Ok, "controle");
        Assert.False(TaskSchemaValidator.Validate("process.kill", P("""{"pid":1234,"name":"a\u007Fb"}""")).Ok, "DEL");

        // Tipo desconhecido e propriedades extras.
        Assert.False(TaskSchemaValidator.Validate("shell.exec", P("""{"cmd":"dir"}""")).Ok);
        Assert.False(TaskSchemaValidator.Validate("powershell.run", P("{}")).Ok);
        Assert.False(TaskSchemaValidator.Validate("process.list", P("""{"x":1}""")).Ok);
        Assert.False(TaskSchemaValidator.Validate("service.start", P("""{"name":"Spooler","extra":"x"}""")).Ok);
        Assert.False(TaskSchemaValidator.Validate("process.kill", P("""{"pid":99,"name":"x","force":true}""")).Ok);

        // Chave duplicada (validar uma, executar outra) e params que não são objeto.
        Assert.False(TaskSchemaValidator.Validate("service.stop", P("""{"name":"Spooler","name":"RpcSs"}""")).Ok);
        Assert.False(TaskSchemaValidator.Validate("system.info", P("[]")).Ok);
        Assert.False(TaskSchemaValidator.Validate("system.info", P("null")).Ok);
    }

    [Fact]
    public void Local_catalog_has_exactly_the_manager_types_and_timeouts()
    {
        Assert.Equal(
            ["agent.restart", "file.fetch", "file.send", "inventory.refresh", "process.kill", "process.list", "service.list", "service.restart", "service.start", "service.stop", "system.info"],
            LocalTaskCatalog.Types.Order());
        Assert.Equal(TimeSpan.FromSeconds(15), LocalTaskCatalog.Timeout("process.kill"));
        Assert.Equal(TimeSpan.FromSeconds(120), LocalTaskCatalog.Timeout("inventory.refresh"));
        Assert.Equal(TimeSpan.FromSeconds(60), LocalTaskCatalog.Timeout("service.restart"));

        foreach (var name in new[] { "RpcSs", "rpcss", "DcomLaunch", "LSM", "SamSs", "EventLog", "BFE", "mpssvc", "WinDefend", "CryptSvc", "winmgmt", "TOOLITAGENT" })
        {
            Assert.True(LocalTaskCatalog.IsProtectedService(name), name);
        }

        Assert.False(LocalTaskCatalog.IsProtectedService("Spooler"));
    }

    [Fact]
    public async Task Unknown_type_is_rejected_without_executing()
    {
        var spy = new SpyHandler("shell.exec");
        var known = new SpyHandler("system.info");
        var runner = Runner(null, spy, known);

        // Mesmo "aprovado" pelo Manager (risk high), tipo fora do catálogo local não roda.
        var result = await runner.RunAsync(Assignment("shell.exec", """{"cmd":"format c:"}"""), default);
        Assert.Equal("rejected", result.Status);
        Assert.Equal(0, spy.Calls);
        Assert.Equal("0b9e6a52-3c1d-4e7f-8a21-9d5c4b3a2f10", result.AttemptId);
        Assert.False(string.IsNullOrEmpty(result.Error));

        // Parâmetro extra num tipo conhecido também é recusado sem executar.
        var extra = await runner.RunAsync(Assignment("system.info", """{"x":1}"""), default);
        Assert.Equal("rejected", extra.Status);
        Assert.Equal(0, known.Calls);

        // Tipo do catálogo sem handler registrado: rejected (nunca "completed" vazio).
        var missing = await Runner(null).RunAsync(Assignment("process.list"), default);
        Assert.Equal("rejected", missing.Status);

        var ok = await runner.RunAsync(Assignment("system.info"), default);
        Assert.Equal("completed", ok.Status);
        Assert.Equal(1, known.Calls);
    }

    [Fact]
    public async Task Protected_processes_are_refused()
    {
        var list = new ProtectedProcessList();
        foreach (var name in new[] { "lsass", "LSASS.EXE", "csrss", "csrss.exe", "System", "system", "winlogon", "Winlogon.exe", "wininit", "services", "SERVICES.exe", "smss", " smss.exe " })
        {
            Assert.True(list.IsProtected(name, 4242), name);
        }

        Assert.True(list.IsProtected("notepad", Environment.ProcessId), "o próprio Agent (pelo PID)");
        Assert.False(list.IsProtected("notepad", 4242));
        Assert.False(list.IsProtected("lsass2", 4242));

        // Antivírus registrado vem de uma fonte (WMI); falha na consulta não derruba nada.
        var withAv = new ProtectedProcessList(new FakeAvSource(() => ["AvEngine"]));
        Assert.True(withAv.IsProtected("avengine.EXE", 4242));
        var broken = new ProtectedProcessList(new FakeAvSource(() => throw new InvalidOperationException("WMI fora")));
        Assert.True(broken.IsProtected("lsass", 4242));
        Assert.False(broken.IsProtected("notepad", 4242));

        // Pelo handler: nome pedido protegido, ou processo vivo protegido no PID, é recusado sem matar.
        var api = new FakeProcessApi().With(700, "lsass").With(800, "csrss");
        var runner = Runner(null, new ProcessKillHandler(api, list));
        var r1 = await runner.RunAsync(Assignment("process.kill", """{"pid":700,"name":"lsass.exe"}"""), default);
        Assert.Equal("rejected", r1.Status);
        var r2 = await runner.RunAsync(Assignment("process.kill", """{"pid":800,"name":"CSRSS"}"""), default);
        Assert.Equal("rejected", r2.Status);
        var r3 = await runner.RunAsync(Assignment("process.kill", "{\"pid\":" + Environment.ProcessId + ",\"name\":\"testhost\"}"), default);
        Assert.Equal("rejected", r3.Status);
        Assert.Empty(api.Killed);
    }

    private sealed class FakeAvSource(Func<IReadOnlyList<string>> names) : IProtectedProcessSource
    {
        public IReadOnlyList<string> GetProcessNames() => names();
    }

    [Fact]
    public async Task Kill_requires_matching_name()
    {
        var api = new FakeProcessApi().With(1234, "calc").With(2000, "notepad");
        var runner = Runner(null, new ProcessKillHandler(api, new ProtectedProcessList()));

        // O PID foi reutilizado por outro executável: recusa.
        var reused = await runner.RunAsync(Assignment("process.kill", """{"pid":1234,"name":"notepad.exe"}"""), default);
        Assert.Equal("rejected", reused.Status);
        Assert.Contains("pid reuse", reused.Error);
        Assert.Empty(api.Killed);

        // Processo inexistente: failed com motivo.
        var gone = await runner.RunAsync(Assignment("process.kill", """{"pid":9999,"name":"notepad.exe"}"""), default);
        Assert.Equal("failed", gone.Status);
        Assert.Contains("not found", gone.Error);

        // Nome confere (com ou sem .exe, caixa diferente): encerra.
        var ok = await runner.RunAsync(Assignment("process.kill", """{"pid":2000,"name":"NOTEPAD.EXE"}"""), default);
        Assert.Equal("completed", ok.Status);
        Assert.Equal([2000], api.Killed);
    }

    [Fact]
    public async Task Critical_or_unverifiable_process_is_refused_without_killing()
    {
        // svchost que hospeda DcomLaunch/RpcSs: nome comum, mas marcado como crítico pelo Windows.
        var api = new FakeProcessApi().With(900, "svchost").With(901, "svchost").With(902, "notepad");
        api.Critical[900] = true;
        api.Critical[901] = null;   // acesso negado ao verificar
        var runner = Runner(null, new ProcessKillHandler(api, new ProtectedProcessList()));

        var critical = await runner.RunAsync(Assignment("process.kill", """{"pid":900,"name":"svchost.exe"}"""), default);
        Assert.Equal("rejected", critical.Status);
        Assert.Contains("crítico", critical.Error);

        var unknown = await runner.RunAsync(Assignment("process.kill", """{"pid":901,"name":"svchost"}"""), default);
        Assert.Equal("rejected", unknown.Status);
        Assert.Equal("não foi possível verificar se o processo é crítico", unknown.Error);
        Assert.Empty(api.Killed);

        var ok = await runner.RunAsync(Assignment("process.kill", """{"pid":902,"name":"notepad"}"""), default);
        Assert.Equal("completed", ok.Status);
        Assert.Equal([902], api.Killed);
    }

    [Fact]
    public async Task Output_over_16KiB_is_truncated_on_utf8_boundary()
    {
        // "a" + "é"×9000 = 18001 bytes: o corte em 16384 cairia no meio de um "é".
        var big = "a" + new string('é', 9000);
        var emoji = string.Concat(Enumerable.Repeat("😀", 5000)); // 4 bytes cada
        var runner = Runner(null, new SpyHandler("system.info", (_, _) => Task.FromResult(new TaskOutcome("completed", 0, big, emoji, null))));

        var result = await runner.RunAsync(Assignment("system.info"), default);

        Assert.True(result.Truncated);
        var stdout = Encoding.UTF8.GetBytes(result.Stdout!);
        var stderr = Encoding.UTF8.GetBytes(result.Stderr!);
        Assert.Equal(16383, stdout.Length);
        Assert.Equal(16384, stderr.Length);
        Assert.Equal(result.Stdout, new UTF8Encoding(false, true).GetString(stdout)); // UTF-8 válido, sem U+FFFD
        Assert.DoesNotContain('�', result.Stdout!);
        Assert.StartsWith("aéé", result.Stdout);
        Assert.EndsWith("😀", result.Stderr);

        // Abaixo do limite: intacto e sem marca de truncado.
        var small = await Runner(null, new SpyHandler("system.info", (_, _) => Task.FromResult(TaskOutcome.Completed("pequeno")))).RunAsync(Assignment("system.info"), default);
        Assert.False(small.Truncated);
        Assert.Equal("pequeno", small.Stdout);
        Assert.Equal(16384, TaskRunner.MaxOutputBytes);
    }

    [Fact]
    public async Task Timeout_yields_failed_timeout()
    {
        var slow = new SpyHandler("system.info", async (_, ct) =>
        {
            await Task.Delay(Timeout.Infinite, ct);

            return TaskOutcome.Completed("nunca");
        });

        var started = DateTimeOffset.UtcNow;
        var result = await Runner(null, slow).RunAsync(Assignment("system.info", timeoutSeconds: 1), default);

        Assert.Equal("failed", result.Status);
        Assert.Equal("timeout", result.Error);
        Assert.InRange((DateTimeOffset.UtcNow - started).TotalSeconds, 0.5, 10);
        Assert.True(result.DurationMs >= 0);

        // Handler que ignora o token (API síncrona travada) também não prende o runner além do timeout.
        var stuck = new SpyHandler("service.list", async (_, _) =>
        {
            await Task.Delay(TimeSpan.FromSeconds(30), CancellationToken.None); // ignora o token de propósito

            return TaskOutcome.Completed("tarde");
        });
        var stuckResult = await Runner(null, stuck).RunAsync(Assignment("service.list", timeoutSeconds: 1), default);
        Assert.Equal("failed", stuckResult.Status);
        Assert.Equal("timeout", stuckResult.Error);
    }

    [Fact]
    public async Task Cancel_via_coordinator_yields_cancelled()
    {
        var coordinator = new TaskCoordinator();
        var slow = new SpyHandler("system.info", async (_, ct) =>
        {
            await Task.Delay(Timeout.Infinite, ct);

            return TaskOutcome.Completed("nunca");
        });

        var running = Runner(coordinator, slow).RunAsync(Assignment("system.info", taskId: "aaaa"), default);
        await slow.Started.Task.WaitAsync(TimeSpan.FromSeconds(10));

        coordinator.Cancel(["outra-tarefa"]); // id de outra tarefa: nada acontece
        await Task.Delay(100);
        Assert.False(running.IsCompleted);

        coordinator.Cancel(["aaaa"]);
        var result = await running.WaitAsync(TimeSpan.FromSeconds(10));

        Assert.Equal("cancelled", result.Status);

        // Exceção inesperada do handler vira failed (nunca derruba o poller).
        var boom = new SpyHandler("system.info", (_, _) => throw new InvalidOperationException("quebrou"));
        var failed = await Runner(null, boom).RunAsync(Assignment("system.info"), default);
        Assert.Equal("failed", failed.Status);
        Assert.Contains("quebrou", failed.Error);
    }
}
