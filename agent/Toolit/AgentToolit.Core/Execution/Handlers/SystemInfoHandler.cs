using System.Runtime.InteropServices;
using System.Text.Json;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>system.info: dados básicos do equipamento, só de <see cref="Environment"/>/<see cref="RuntimeInformation"/>.</summary>
public sealed class SystemInfoHandler : ITaskHandler
{
    public string Type => "system.info";

    public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct)
    {
        var info = new
        {
            Hostname = Environment.MachineName,
            Os = new
            {
                Description = RuntimeInformation.OSDescription,
                Version = Environment.OSVersion.Version.ToString(),
                Arch = RuntimeInformation.OSArchitecture.ToString().ToLowerInvariant(),
            },
            UptimeSeconds = Environment.TickCount64 / 1000,
            ProcessorCount = Environment.ProcessorCount,
            Is64BitOs = Environment.Is64BitOperatingSystem,
            Runtime = RuntimeInformation.FrameworkDescription,
            AgentPid = Environment.ProcessId,
            CollectedAt = DateTimeOffset.UtcNow,
        };

        return Task.FromResult(TaskOutcome.Completed(JsonSerializer.Serialize(info, Json.Options)));
    }
}
