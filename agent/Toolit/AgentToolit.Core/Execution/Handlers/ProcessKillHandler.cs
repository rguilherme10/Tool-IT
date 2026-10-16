using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// process.kill: encerra um processo por PID, exigindo que o processo vivo naquele PID tenha o nome esperado
/// (protege contra reutilização de PID). Processos protegidos nunca são encerrados, nem com aprovação.
/// </summary>
public sealed class ProcessKillHandler(IProcessApi api, ProtectedProcessList protectedList) : ITaskHandler
{
    public string Type => "process.kill";

    public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        var pid = parameters.GetProperty("pid").GetInt32();
        var expected = parameters.GetProperty("name").GetString()!;

        if (protectedList.IsProtected(expected, pid))
        {
            return Task.FromResult(TaskOutcome.Rejected("protected process"));
        }

        var live = api.Get(pid);
        if (live is null)
        {
            return Task.FromResult(TaskOutcome.Failed("process not found"));
        }

        if (protectedList.IsProtected(live.Name, live.Pid))
        {
            return Task.FromResult(TaskOutcome.Rejected("protected process"));
        }

        if (!string.Equals(ProtectedProcessList.Normalize(live.Name), ProtectedProcessList.Normalize(expected), StringComparison.OrdinalIgnoreCase))
        {
            return Task.FromResult(TaskOutcome.Rejected($"pid reuse: pid {pid} is now '{live.Name}'"));
        }

        // Processo crítico (ex.: svchost que hospeda DcomLaunch/RpcSs): encerrá-lo como LocalSystem derruba a máquina.
        switch (api.IsCritical(pid))
        {
            case true:
                return Task.FromResult(TaskOutcome.Rejected("protected process: processo crítico do sistema"));
            case null when api.Get(pid) is null:
                return Task.FromResult(TaskOutcome.Failed("process not found"));
            case null:
                return Task.FromResult(TaskOutcome.Rejected("não foi possível verificar se o processo é crítico"));
        }

        ct.ThrowIfCancellationRequested();
        try
        {
            api.Kill(pid);
        }
        catch (Exception ex) when (ex is ArgumentException or InvalidOperationException)
        {
            return Task.FromResult(TaskOutcome.Failed("process not found"));   // terminou sozinho entre a checagem e o kill
        }

        return Task.FromResult(TaskOutcome.Completed($"Processo {live.Name} (PID {pid}) encerrado."));
    }
}
