using System.ServiceProcess;

namespace AgentToolit.Core.Execution.Windows;

/// <summary>
/// Serviços via <see cref="ServiceController"/> (sem sc.exe/PowerShell). A resolução é sempre pelo nome do serviço
/// enumerado (o construtor do ServiceController aceitaria também o nome de exibição).
/// </summary>
public sealed class WindowsServiceApi : IServiceApi
{
    private static readonly TimeSpan PollInterval = TimeSpan.FromMilliseconds(250);

    public IReadOnlyList<ServiceInfo> List()
    {
        var services = ServiceController.GetServices();
        try
        {
            return services.Select(Describe).Where(s => s is not null).Select(s => s!).ToList();
        }
        finally
        {
            foreach (var s in services)
            {
                s.Dispose();
            }
        }
    }

    public ServiceInfo? Get(string name)
    {
        var services = ServiceController.GetServices();
        try
        {
            var match = services.FirstOrDefault(s => string.Equals(s.ServiceName, name, StringComparison.OrdinalIgnoreCase));

            return match is null ? null : Describe(match);
        }
        finally
        {
            foreach (var s in services)
            {
                s.Dispose();
            }
        }
    }

    public async Task StartAsync(string name, CancellationToken ct)
    {
        using var service = Open(name);
        if (service.Status is ServiceControllerStatus.Running)
        {
            return;
        }

        if (service.Status is not ServiceControllerStatus.StartPending)
        {
            service.Start();
        }

        await WaitForAsync(service, ServiceControllerStatus.Running, ct);
    }

    public async Task StopAsync(string name, CancellationToken ct)
    {
        using var service = Open(name);
        if (service.Status is ServiceControllerStatus.Stopped)
        {
            return;
        }

        if (service.Status is not ServiceControllerStatus.StopPending)
        {
            // Sem parar dependentes: com dependentes ativos o Windows recusa e a tarefa falha com o motivo.
            service.Stop(stopDependentServices: false);
        }

        await WaitForAsync(service, ServiceControllerStatus.Stopped, ct);
    }

    public async Task RestartAsync(string name, CancellationToken ct)
    {
        await StopAsync(name, ct);
        await StartAsync(name, ct);
    }

    private ServiceController Open(string name)
    {
        var canonical = Get(name)?.Name ?? throw new InvalidOperationException("service not found");

        return new ServiceController(canonical);
    }

    private static async Task WaitForAsync(ServiceController service, ServiceControllerStatus target, CancellationToken ct)
    {
        while (true)
        {
            service.Refresh();
            if (service.Status == target)
            {
                return;
            }

            await Task.Delay(PollInterval, ct);   // o timeout do tipo (runner) encerra a espera
        }
    }

    private static ServiceInfo? Describe(ServiceController s)
    {
        try
        {
            return new ServiceInfo(s.ServiceName, s.DisplayName, Snake(s.Status.ToString()), StartType(s));
        }
        catch (InvalidOperationException)
        {
            return null;   // removido durante a enumeração
        }
    }

    private static string StartType(ServiceController s)
    {
        try
        {
            return s.StartType.ToString().ToLowerInvariant();
        }
        catch (InvalidOperationException)
        {
            return "unknown";
        }
    }

    /// <summary>StartPending → start_pending.</summary>
    private static string Snake(string pascal) =>
        string.Concat(pascal.Select((c, i) => char.IsUpper(c) && i > 0 ? "_" + char.ToLowerInvariant(c) : char.ToLowerInvariant(c).ToString()));
}
