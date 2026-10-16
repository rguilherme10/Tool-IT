using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>inventory.refresh: coleta o inventário agora (enfileirado e enviado pelo fluxo normal do sender).</summary>
public sealed class InventoryRefreshHandler(IInventoryRefresher refresher) : ITaskHandler
{
    public string Type => "inventory.refresh";

    public async Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        await refresher.RefreshAsync(ct);

        return TaskOutcome.Completed("Inventário coletado e enfileirado para envio.");
    }
}
