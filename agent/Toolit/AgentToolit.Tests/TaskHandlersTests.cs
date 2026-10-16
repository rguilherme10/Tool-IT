using System.Diagnostics;
using System.Text;
using System.Text.Json;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Execution.Handlers;
using AgentToolit.Core.Execution.Windows;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit.Abstractions;
using static AgentToolit.Tests.TaskExecutionTests;

namespace AgentToolit.Tests;

/// <summary>
/// Handlers. Os de leitura rodam de verdade nesta máquina; os que alteram o sistema só contra um processo
/// descartável iniciado pelo próprio teste ou contra fakes (nenhum serviço real é parado/iniciado).
/// </summary>
public class TaskHandlersTests(ITestOutputHelper output)
{
    private static readonly JsonElement Empty = P("{}");

    [Fact]
    public async Task SystemInfo_returns_json_with_hostname_and_os()
    {
        var outcome = await new SystemInfoHandler().ExecuteAsync(Empty, default);

        Assert.Equal("completed", outcome.Status);
        Assert.Equal(0, outcome.ExitCode);
        var json = JsonDocument.Parse(outcome.Stdout!).RootElement;
        Assert.Equal(Environment.MachineName, json.GetProperty("hostname").GetString());
        var os = json.GetProperty("os");
        Assert.False(string.IsNullOrWhiteSpace(os.GetProperty("description").GetString()));
        Assert.False(string.IsNullOrWhiteSpace(os.GetProperty("version").GetString()));
        Assert.False(string.IsNullOrWhiteSpace(os.GetProperty("arch").GetString()));
        Assert.True(json.GetProperty("uptime_seconds").GetInt64() > 0);
        Assert.Equal(Environment.ProcessorCount, json.GetProperty("processor_count").GetInt32());
    }

    [Fact]
    public async Task ProcessList_includes_current_process_and_fits_16KiB()
    {
        // Real (só leitura).
        var outcome = await new ProcessListHandler(new WindowsProcessApi()).ExecuteAsync(Empty, default);
        Assert.Equal("completed", outcome.Status);
        Assert.True(Encoding.UTF8.GetByteCount(outcome.Stdout!) <= 16384);
        var json = JsonDocument.Parse(outcome.Stdout!).RootElement;
        var items = json.GetProperty("processes").EnumerateArray().ToList();
        Assert.NotEmpty(items);
        Assert.True(json.GetProperty("total").GetInt32() >= items.Count);
        var mb = items.Select(i => i.GetProperty("working_set_mb").GetDouble()).ToList();
        Assert.Equal(mb.OrderByDescending(x => x), mb); // ordenado por memória
        var self = items.FirstOrDefault(i => i.GetProperty("pid").GetInt32() == Environment.ProcessId);
        Assert.True(self.ValueKind == JsonValueKind.Object || json.GetProperty("truncated").GetBoolean(), "processo atual ausente sem truncamento");
        output.WriteLine($"process.list real: {items.Count} de {json.GetProperty("total").GetInt32()} processos, {Encoding.UTF8.GetByteCount(outcome.Stdout!)} bytes, truncated={json.GetProperty("truncated").GetBoolean()}");

        // Determinístico: 2000 processos não cabem; descarta os de menor memória e marca truncated.
        var fake = new FakeProcessApi();
        for (var pid = 10; pid < 2010; pid++)
        {
            fake.With(pid, "processo-com-nome-longo-" + pid, workingSetMb: pid);
        }

        fake.With(Environment.ProcessId, "testhost", workingSetMb: 1_000_000);
        var big = await new ProcessListHandler(fake).ExecuteAsync(Empty, default);
        Assert.True(Encoding.UTF8.GetByteCount(big.Stdout!) <= 16384);
        var bigJson = JsonDocument.Parse(big.Stdout!).RootElement; // JSON íntegro (nunca cortado no meio)
        Assert.True(bigJson.GetProperty("truncated").GetBoolean());
        Assert.Equal(2001, bigJson.GetProperty("total").GetInt32());
        var kept = bigJson.GetProperty("processes").EnumerateArray().Select(i => i.GetProperty("pid").GetInt32()).ToList();
        Assert.Equal(Environment.ProcessId, kept[0]);
        Assert.Equal(2009, kept[1]);
        Assert.True(kept.Count > 50);
        Assert.DoesNotContain(10, kept);
        Assert.True(Encoding.UTF8.GetByteCount(big.Stdout!) > 15000, "deveria aproveitar quase todo o limite");
    }

    [Fact]
    public async Task ServiceList_returns_known_service_states()
    {
        var api = new WindowsServiceApi();

        // RpcSs e EventLog sempre rodam no Windows (só leitura).
        Assert.Equal("running", api.Get("RpcSs")?.State);
        Assert.Equal("running", api.Get("eventlog")?.State);
        Assert.Equal("EventLog", api.Get("eventlog")?.Name); // nome canônico
        Assert.Null(api.Get("Toolit-Servico-Inexistente-" + Guid.NewGuid().ToString("N")[..8]));
        Assert.Null(api.Get("Windows Event Log")); // nome de exibição não vale como nome

        var outcome = await new ServiceListHandler(api).ExecuteAsync(Empty, default);
        Assert.Equal("completed", outcome.Status);
        Assert.True(Encoding.UTF8.GetByteCount(outcome.Stdout!) <= 16384);
        var json = JsonDocument.Parse(outcome.Stdout!).RootElement;
        var services = json.GetProperty("services").EnumerateArray().ToList();
        Assert.NotEmpty(services);
        Assert.All(services, s => Assert.False(string.IsNullOrEmpty(s.GetProperty("state").GetString())));
        Assert.Contains(services, s => s.GetProperty("state").GetString() == "running");
        Assert.Contains(services, s => s.GetProperty("name").GetString() == "RpcSs"); // em execução vêm primeiro
        output.WriteLine($"service.list real: {services.Count} de {json.GetProperty("total").GetInt32()} serviços, {Encoding.UTF8.GetByteCount(outcome.Stdout!)} bytes");
    }

    [Fact]
    public async Task ProcessKill_kills_a_disposable_child_process()
    {
        // Processo descartável criado pelo próprio teste (sem shell: argumentos em lista).
        var start = new ProcessStartInfo("ping.exe") { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true };
        foreach (var arg in new[] { "-n", "60", "127.0.0.1" })
        {
            start.ArgumentList.Add(arg);
        }

        using var child = Process.Start(start)!;
        try
        {
            var api = new WindowsProcessApi();
            var handler = new ProcessKillHandler(api, new ProtectedProcessList());

            // Só leitura: o filho descartável e o próprio processo de teste não são críticos.
            Assert.False(api.IsCritical(child.Id));
            Assert.False(api.IsCritical(Environment.ProcessId));

            // Nome errado para aquele PID: recusa e o processo continua vivo.
            var wrong = await handler.ExecuteAsync(P("{\"pid\":" + child.Id + ",\"name\":\"notepad.exe\"}"), default);
            Assert.Equal("rejected", wrong.Status);
            Assert.False(child.HasExited);

            var outcome = await handler.ExecuteAsync(P("{\"pid\":" + child.Id + ",\"name\":\"PING.EXE\"}"), default);
            Assert.Equal("completed", outcome.Status);
            Assert.True(child.WaitForExit(10_000), "o ping deveria ter sido encerrado");
            Assert.Null(api.Get(child.Id));

            // Já encerrado: failed "not found".
            var again = await handler.ExecuteAsync(P("{\"pid\":" + child.Id + ",\"name\":\"ping\"}"), default);
            Assert.Equal("failed", again.Status);
        }
        finally
        {
            if (!child.HasExited)
            {
                child.Kill();
            }
        }
    }

    internal sealed class FakeServiceApi : IServiceApi
    {
        public Dictionary<string, ServiceInfo> Services { get; } = new(StringComparer.OrdinalIgnoreCase);

        public List<string> Calls { get; } = [];

        public Exception? Fail { get; set; }

        public IReadOnlyList<ServiceInfo> List() => Services.Values.ToList();

        public ServiceInfo? Get(string name) => Services.GetValueOrDefault(name);

        public Task StartAsync(string name, CancellationToken ct) => Record("start", name);

        public Task StopAsync(string name, CancellationToken ct) => Record("stop", name);

        public Task RestartAsync(string name, CancellationToken ct) => Record("restart", name);

        private Task Record(string op, string name)
        {
            Calls.Add(op + ":" + name);

            return Fail is null ? Task.CompletedTask : Task.FromException(Fail);
        }

        public FakeServiceApi With(string name, string state = "running")
        {
            Services[name] = new ServiceInfo(name, name + " (exibição)", state, "automatic");

            return this;
        }
    }

    private static Dictionary<string, ServiceControlHandler> ServiceHandlers(IServiceApi api) =>
        ServiceControlHandler.All(api).ToDictionary(h => h.Type);

    [Fact]
    public async Task ServiceControl_calls_start_stop_restart_and_reports_missing_service()
    {
        var api = new FakeServiceApi().With("ToolitTesteFake");
        var handlers = ServiceHandlers(api);
        Assert.Equal(["service.restart", "service.start", "service.stop"], handlers.Keys.Order());

        foreach (var type in new[] { "service.start", "service.stop", "service.restart" })
        {
            var outcome = await handlers[type].ExecuteAsync(P("""{"name":"toolittestefake"}"""), default);
            Assert.Equal("completed", outcome.Status);
            Assert.Equal(0, outcome.ExitCode);
        }

        // Usa o nome canônico devolvido pela API.
        Assert.Equal(["start:ToolitTesteFake", "stop:ToolitTesteFake", "restart:ToolitTesteFake"], api.Calls);

        var missing = await handlers["service.stop"].ExecuteAsync(P("""{"name":"NaoExiste"}"""), default);
        Assert.Equal("failed", missing.Status);
        Assert.Equal("service not found", missing.Error);
        Assert.Equal(3, api.Calls.Count);

        api.Fail = new InvalidOperationException("Acesso negado");
        var denied = await handlers["service.start"].ExecuteAsync(P("""{"name":"ToolitTesteFake"}"""), default);
        Assert.Equal("failed", denied.Status);
        Assert.Contains("Acesso negado", denied.Error);
    }

    [Fact]
    public async Task ServiceControl_refuses_protected_service()
    {
        var api = new FakeServiceApi().With("RpcSs").With("Winmgmt").With("ToolitAgent").With("WinDefend");
        var handlers = ServiceHandlers(api);

        foreach (var (type, name) in new[] { ("service.stop", "RpcSs"), ("service.restart", "winmgmt"), ("service.stop", "toolitagent"), ("service.restart", "WINDEFEND") })
        {
            var outcome = await handlers[type].ExecuteAsync(P("{\"name\":\"" + name + "\"}"), default);
            Assert.Equal("rejected", outcome.Status);
            Assert.Contains("protected", outcome.Error);
        }

        Assert.Empty(api.Calls);

        // Iniciar um serviço crítico não derruba nada (mesma regra do Manager).
        var start = await handlers["service.start"].ExecuteAsync(P("""{"name":"RpcSs"}"""), default);
        Assert.Equal("completed", start.Status);
        Assert.Equal(["start:RpcSs"], api.Calls);
    }

    private sealed class FakeRefresher : IInventoryRefresher
    {
        public int Calls;

        public Task RefreshAsync(CancellationToken ct)
        {
            Calls++;

            return Task.CompletedTask;
        }
    }

    [Fact]
    public async Task InventoryRefresh_collects_and_AgentRestart_only_flags_the_restart()
    {
        var refresher = new FakeRefresher();
        var inventory = await new InventoryRefreshHandler(refresher).ExecuteAsync(Empty, default);
        Assert.Equal("completed", inventory.Status);
        Assert.Equal(1, refresher.Calls);

        // O handler não para nada: só marca o pedido; quem para o host é o poller, depois de enviar o resultado.
        var restart = await new AgentRestartHandler().ExecuteAsync(Empty, default);
        Assert.Equal("completed", restart.Status);
        Assert.True(restart.RestartAgent);
        Assert.False(inventory.RestartAgent);
    }

    private sealed class FakeLifetime : IHostApplicationLifetime
    {
        public int Stops;

        public CancellationToken ApplicationStarted => CancellationToken.None;

        public CancellationToken ApplicationStopping => CancellationToken.None;

        public CancellationToken ApplicationStopped => CancellationToken.None;

        public void StopApplication() => Stops++;
    }

    [Fact]
    public void AgentRestarter_stops_host_with_nonzero_exit_code()
    {
        var lifetime = new FakeLifetime();
        int? exitCode = null;
        var restarter = new AgentRestarter(lifetime, NullLogger<AgentRestarter>.Instance, code => exitCode = code);

        restarter.RequestRestart();

        Assert.Equal(1, lifetime.Stops);
        Assert.NotNull(exitCode);
        Assert.NotEqual(0, exitCode);
        Assert.Equal(0, Environment.ExitCode); // o processo de teste não foi afetado
    }
}
