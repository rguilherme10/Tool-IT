using AgentToolit.Collectors.Inventory;
using AgentToolit.Collectors.Metrics;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Queue;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Collectors;

/// <summary>Coleta e ENFILEIRA (nunca envia direto): o envio e a tolerância a falhas ficam no SenderService.</summary>
public sealed class CollectionService(
    MetricsCollector metrics, InventoryCollector inventory, OutboundQueue queue, AgentRuntime runtime,
    ILogger<CollectionService> logger)
{
    public const int MetricsPriority = 5;
    public const int InventoryPriority = 10;

    public async Task CollectMetricsAsync(CancellationToken ct)
    {
        if (!runtime.IsEnrolled || runtime.IsRevoked)
        {
            return;
        }

        var sample = await metrics.CollectAsync(ct);
        var dropped = queue.Enqueue(OutboundQueue.Metrics, Json.Serialize(sample), MetricsPriority);
        if (dropped > 0)
        {
            logger.LogWarning("Fila local cheia: {Dropped} item(ns) mais antigo(s) descartado(s).", dropped);
        }
    }

    public async Task CollectInventoryAsync(CancellationToken ct)
    {
        if (!runtime.IsEnrolled || runtime.IsRevoked)
        {
            return;
        }

        var snapshot = await inventory.CollectAsync(ct);
        var summary = string.Join(", ", snapshot.Sections.Select(s => $"{s.Key}={s.Value.Status}"));
        var dropped = queue.Enqueue(OutboundQueue.Inventory, Json.Serialize(snapshot), InventoryPriority);
        logger.LogInformation("Inventário coletado ({Summary}).", summary);
        if (dropped > 0)
        {
            logger.LogWarning("Fila local cheia: {Dropped} item(ns) mais antigo(s) descartado(s).", dropped);
        }
    }
}
