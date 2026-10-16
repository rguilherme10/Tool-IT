using AgentToolit.Core.Config;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Host;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Queue;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Services;

/// <summary>
/// Um ciclo de heartbeat. Devolve quanto esperar até o próximo: intervalo normal em sucesso, backoff com jitter em falha.
/// A lógica fica aqui (e não no BackgroundService) para ser testável com relógio e rede simulados.
/// </summary>
public sealed class HeartbeatService(
    ManagerClient client, AgentRuntime runtime, AgentSettings settings, IHostFacts host, OutboundQueue queue,
    ILogger<HeartbeatService> logger, Backoff? backoff = null, TaskCoordinator? tasks = null)
{
    private readonly Backoff _backoff = backoff ?? new Backoff();
    private bool _revokedLogged;

    public static readonly TimeSpan IdleWhenNotEnrolled = TimeSpan.FromSeconds(30);
    public static readonly TimeSpan IdleWhenRevoked = TimeSpan.FromMinutes(5);

    public async Task<TimeSpan> TickAsync(CancellationToken ct)
    {
        if (!runtime.IsEnrolled)
        {
            return IdleWhenNotEnrolled;
        }

        if (runtime.IsRevoked)
        {
            if (!_revokedLogged)
            {
                logger.LogWarning("Instalação revogada pelo Manager: coletas e envios suspensos. Faça um novo enrollment para reativar.");
                _revokedLogged = true;
            }

            return IdleWhenRevoked;
        }

        var request = new HeartbeatRequest(
            UptimeSeconds: host.UptimeSeconds,
            ServiceState: "running",
            AgentVersion: client.AgentVersion,
            CurrentUser: host.GetInteractiveUser(),
            IpAddresses: host.GetIpAddresses(),
            PendingEvents: queue.Count());

        try
        {
            var response = await client.HeartbeatAsync(request, ct);
            _backoff.Reset();
            logger.LogDebug("Heartbeat ok (config v{Version}, tarefas={Tasks})", response.ConfigVersion, response.TasksAvailable);

            // Sinais para o poller de tarefas: cancela as canceladas no Manager e acorda o poll se há tarefa.
            if (response.CancelTaskIds is { Count: > 0 } cancelIds)
            {
                tasks?.Cancel(cancelIds);
            }

            if (response.TasksAvailable)
            {
                tasks?.Notify();
            }

            return TimeSpan.FromSeconds(settings.HeartbeatSeconds);
        }
        catch (AgentRevokedException)
        {
            runtime.MarkRevoked();
            logger.LogWarning("O Manager respondeu que esta instalação foi revogada.");

            return IdleWhenRevoked;
        }
        catch (ManagerUnavailableException ex)
        {
            var delay = ex.RetryAfter ?? _backoff.Next();
            logger.LogWarning("Manager indisponível ({Reason}); nova tentativa em {Seconds:F0}s.", ex.Message, delay.TotalSeconds);

            return delay;
        }
        catch (ManagerAuthException)
        {
            var delay = _backoff.Next();
            logger.LogWarning("Manager recusou a autenticação (verifique o relógio do equipamento); nova tentativa em {Seconds:F0}s.", delay.TotalSeconds);

            return delay;
        }
        catch (ManagerRejectedException ex)
        {
            logger.LogError("Heartbeat rejeitado: {Error}", ex.Message);

            return TimeSpan.FromSeconds(Math.Max(settings.HeartbeatSeconds, 60));
        }
    }
}
