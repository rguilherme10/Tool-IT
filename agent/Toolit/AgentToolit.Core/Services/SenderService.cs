using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Queue;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Services;

/// <summary>
/// Esvazia a fila local para o Manager. Itens só saem da fila após confirmação (202/200);
/// falha transitória mantém tudo e aplica backoff; rejeição definitiva (4xx) descarta o item "envenenado".
/// </summary>
public sealed class SenderService(
    ManagerClient client, AgentRuntime runtime, AgentSettings settings, OutboundQueue queue,
    ILogger<SenderService> logger, Backoff? backoff = null)
{
    private readonly Backoff _backoff = backoff ?? new Backoff();

    public static readonly TimeSpan Interval = TimeSpan.FromSeconds(10);

    public async Task<TimeSpan> TickAsync(CancellationToken ct)
    {
        if (!runtime.IsEnrolled || runtime.IsRevoked)
        {
            return Interval;
        }

        try
        {
            await DrainInventoryAsync(ct);
            await DrainMetricsAsync(ct);
            _backoff.Reset();

            return Interval;
        }
        catch (AgentRevokedException)
        {
            runtime.MarkRevoked();
            logger.LogWarning("Instalação revogada durante o envio; fila preservada, envios suspensos.");

            return Interval;
        }
        catch (ManagerUnavailableException ex)
        {
            var delay = ex.RetryAfter ?? Max(_backoff.Next(), Interval);
            logger.LogWarning("Envio adiado ({Reason}); {Pending} item(ns) na fila; nova tentativa em {Seconds:F0}s.",
                ex.Message, queue.Count(), delay.TotalSeconds);

            return delay;
        }
        catch (ManagerAuthException)
        {
            return Max(_backoff.Next(), Interval);
        }
    }

    private static TimeSpan Max(TimeSpan a, TimeSpan b) => a > b ? a : b;

    private async Task DrainInventoryAsync(CancellationToken ct)
    {
        foreach (var item in queue.Peek(OutboundQueue.Inventory, 5))
        {
            var request = Json.TryDeserialize<InventoryRequest>(item.Payload);
            if (request is null)
            {
                queue.Delete([item.Id]);
                continue;
            }

            try
            {
                await client.SendInventoryAsync(request, ct);
                queue.Delete([item.Id]);
                logger.LogInformation("Inventário {Snapshot} enviado.", request.SnapshotId);
            }
            catch (ManagerRejectedException ex)
            {
                // Reenviar o mesmo conteúdo não resolve: descarta para não travar a fila.
                logger.LogError("Inventário {Snapshot} rejeitado e descartado: {Error}", request.SnapshotId, ex.Message);
                queue.Delete([item.Id]);
            }
        }
    }

    private async Task DrainMetricsAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            var items = queue.Peek(OutboundQueue.Metrics, settings.MetricsBatchSize);
            if (items.Count == 0)
            {
                return;
            }

            var samples = new List<MetricSample>();
            var poison = new List<long>();
            foreach (var item in items)
            {
                var sample = Json.TryDeserialize<MetricSample>(item.Payload);
                if (sample is null)
                {
                    poison.Add(item.Id);
                }
                else
                {
                    samples.Add(sample);
                }
            }

            if (poison.Count > 0)
            {
                queue.Delete(poison);
            }

            if (samples.Count == 0)
            {
                continue;
            }

            try
            {
                var ack = await client.SendMetricsAsync(new MetricsBatch(samples), ct);
                queue.Delete(items.Select(i => i.Id).Except(poison));
                logger.LogDebug("Métricas enviadas: {Accepted} novas, {Duplicates} duplicadas.", ack.Accepted, ack.Duplicates);
            }
            catch (ManagerRejectedException ex)
            {
                logger.LogError("Lote de {Count} métrica(s) rejeitado e descartado: {Error}", samples.Count, ex.Message);
                queue.Delete(items.Select(i => i.Id));
            }

            if (items.Count < settings.MetricsBatchSize)
            {
                return;
            }
        }
    }
}
