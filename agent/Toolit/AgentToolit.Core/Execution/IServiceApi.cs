namespace AgentToolit.Core.Execution;

/// <summary>
/// Serviço do Windows. <see cref="Name"/> é o nome canônico (ServiceName); State: running, stopped, paused,
/// start_pending, stop_pending...; StartType: automatic, manual, disabled, boot, system.
/// </summary>
public sealed record ServiceInfo(string Name, string DisplayName, string State, string StartType);

/// <summary>Abstração sobre <see cref="System.ServiceProcess.ServiceController"/> (fakes nos testes).</summary>
public interface IServiceApi
{
    IReadOnlyList<ServiceInfo> List();

    /// <summary>Busca só pelo nome do serviço (sem diferenciar maiúsculas), nunca pelo nome de exibição. <c>null</c> se não existe.</summary>
    ServiceInfo? Get(string name);

    /// <summary>Inicia e espera "running" (já em execução: nada a fazer). Cancelável.</summary>
    Task StartAsync(string name, CancellationToken ct);

    /// <summary>Para e espera "stopped" (já parado: nada a fazer). Não para dependentes. Cancelável.</summary>
    Task StopAsync(string name, CancellationToken ct);

    Task RestartAsync(string name, CancellationToken ct);
}
