using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// process.list: JSON compacto ordenado por memória (working set). Se não couber em 16 KiB, descarta os de menor
/// memória e marca <c>truncated</c>; <c>total</c> sempre informa quantos existiam. Sem caminho do executável:
/// quase dobraria cada item (medido: ~80 processos em vez de ~150 numa máquina com 422).
/// </summary>
public sealed class ProcessListHandler(IProcessApi api) : ITaskHandler
{
    public string Type => "process.list";

    public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        var processes = api.List()
            .OrderByDescending(p => p.WorkingSetMb)
            .ThenBy(p => p.Pid)
            .Select(p => new ProcessRow(p.Pid, p.Name, Math.Round(p.WorkingSetMb, 1),
                p.CpuSeconds is { } cpu ? Math.Round(cpu, 1) : null, p.StartedAt, p.User))
            .ToList();

        var json = BoundedJson.Serialize<ProcessRow>(processes, (kept, truncated) => new { Processes = kept, Total = processes.Count, Truncated = truncated });

        return Task.FromResult(TaskOutcome.Completed(json));
    }

    private sealed record ProcessRow(int Pid, string Name, double WorkingSetMb, double? CpuSeconds, DateTimeOffset? StartedAt, string? User);
}
