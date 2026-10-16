using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// service.list: serviços com estado e tipo de início. Em execução primeiro, depois por nome; se não couber em
/// 16 KiB, descarta do fim e marca <c>truncated</c> (JSON sempre íntegro).
/// </summary>
public sealed class ServiceListHandler(IServiceApi api) : ITaskHandler
{
    public string Type => "service.list";

    public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        var services = api.List()
            .OrderBy(s => s.State == "running" ? 0 : 1)
            .ThenBy(s => s.Name, StringComparer.OrdinalIgnoreCase)
            .ToList();

        var json = BoundedJson.Serialize<ServiceInfo>(services, (kept, truncated) => new { Services = kept, Total = services.Count, Truncated = truncated });

        return Task.FromResult(TaskOutcome.Completed(json));
    }
}
