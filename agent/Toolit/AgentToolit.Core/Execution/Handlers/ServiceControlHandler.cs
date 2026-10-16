using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// service.start / service.stop / service.restart. Exige serviço existente (por nome de serviço, nunca de exibição)
/// e recusa parar/reiniciar os protegidos da lista local, mesmo com aprovação do Manager.
/// </summary>
public sealed class ServiceControlHandler : ITaskHandler
{
    private readonly IServiceApi _api;

    public ServiceControlHandler(string type, IServiceApi api)
    {
        if (type is not ("service.start" or "service.stop" or "service.restart"))
        {
            throw new ArgumentOutOfRangeException(nameof(type), type, "Tipo de controle de serviço desconhecido.");
        }

        Type = type;
        _api = api;
    }

    public string Type { get; }

    public static IEnumerable<ServiceControlHandler> All(IServiceApi api) =>
    [
        new("service.start", api), new("service.stop", api), new("service.restart", api),
    ];

    public async Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        var requested = parameters.GetProperty("name").GetString()!;
        var stopping = Type != "service.start";

        if (stopping && LocalTaskCatalog.IsProtectedService(requested))
        {
            return TaskOutcome.Rejected("protected service");
        }

        var service = _api.Get(requested);
        if (service is null)
        {
            return TaskOutcome.Failed("service not found");
        }

        if (stopping && LocalTaskCatalog.IsProtectedService(service.Name))
        {
            return TaskOutcome.Rejected("protected service");
        }

        try
        {
            switch (Type)
            {
                case "service.start":
                    await _api.StartAsync(service.Name, ct);
                    break;
                case "service.stop":
                    await _api.StopAsync(service.Name, ct);
                    break;
                default:
                    await _api.RestartAsync(service.Name, ct);
                    break;
            }
        }
        catch (OperationCanceledException)
        {
            throw;   // timeout/cancelamento: o runner classifica
        }
        catch (Exception ex) when (ex is InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return TaskOutcome.Failed(ex.InnerException is { } inner ? ex.Message + " " + inner.Message : ex.Message);
        }

        var verb = Type switch { "service.start" => "iniciado", "service.stop" => "parado", _ => "reiniciado" };

        return TaskOutcome.Completed($"Serviço {service.Name} {verb}.");
    }
}
