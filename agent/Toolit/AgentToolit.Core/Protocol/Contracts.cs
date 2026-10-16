using System.Text.Json;
using System.Text.Json.Serialization;

namespace AgentToolit.Core.Protocol;

/// <summary>Serialização do protocolo: snake_case, sem nulos. Contrato em docs/api/agent-api-v1.md.</summary>
public static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DictionaryKeyPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        WriteIndented = false,
    };

    public static byte[] Serialize<T>(T value) => JsonSerializer.SerializeToUtf8Bytes(value, Options);

    public static T? Deserialize<T>(ReadOnlySpan<byte> utf8) => JsonSerializer.Deserialize<T>(utf8, Options);

    /// <summary>Para dados vindos do disco: conteúdo corrompido vira nulo em vez de derrubar o chamador.</summary>
    public static T? TryDeserialize<T>(ReadOnlySpan<byte> utf8)
        where T : class
    {
        try
        {
            return JsonSerializer.Deserialize<T>(utf8, Options);
        }
        catch (JsonException)
        {
            return null;
        }
    }
}

public sealed record OsInfo(string? Name, string? Version, string? Build, string? Arch);

public sealed record EnrollRequest(
    string EnrollmentToken, string PublicKey, string Hostname, OsInfo Os, string AgentVersion, string? MachineFingerprint);

public sealed record ServerConfig(int HeartbeatSeconds, int MetricsSeconds, int InventoryHours, int MaxPayloadBytes);

public sealed record EnrollResponse(string AgentId, string EndpointId, DateTimeOffset ServerTime, ServerConfig Config);

public sealed record HeartbeatRequest(
    long UptimeSeconds, string ServiceState, string AgentVersion, string? CurrentUser,
    IReadOnlyList<string>? IpAddresses, int PendingEvents);

public sealed record HeartbeatResponse(
    DateTimeOffset ServerTime, int ConfigVersion, bool TasksAvailable, IReadOnlyList<string> CancelTaskIds, JsonElement? UpdateAvailable);

public sealed record VolumeMetric(string Mount, double TotalGb, double FreeGb);

public sealed record MetricSample(
    string SampleId, DateTimeOffset At, double? CpuPercent, long MemTotalMb, long MemAvailableMb,
    long UptimeSeconds, IReadOnlyList<VolumeMetric> Volumes, double? TemperatureC);

public sealed record MetricsBatch(IReadOnlyList<MetricSample> Samples);

public sealed record MetricsAck(int Accepted, int Duplicates, int Rejected = 0);

/// <summary>Resultado por seção do inventário: ok | unavailable | error (nunca "vazio mudo").</summary>
public sealed record SectionResult(string Status, string? Reason, object? Data)
{
    public const string Ok = "ok";
    public const string Unavailable = "unavailable";
    public const string Error = "error";

    public static SectionResult Success(object data) => new(Ok, null, data);
    public static SectionResult NotAvailable(string reason) => new(Unavailable, reason, null);
    public static SectionResult Failed(string reason) => new(Error, reason, null);
}

public sealed record InventoryRequest(string SnapshotId, DateTimeOffset CollectedAt, IReadOnlyDictionary<string, SectionResult> Sections);

public sealed record InventoryAck(bool Accepted, string SnapshotId);

public sealed record ServerMeta(string Protocol, string MinAgentVersion, DateTimeOffset ServerTime);

public sealed record RotateKeyRequest(string NewPublicKey);

public sealed record RotateKeyResponse(bool Rotated, DateTimeOffset PreviousKeyValidUntil);

/// <summary>Tarefa entregue por GET /tasks/next (200). <c>Params</c> é sempre um objeto JSON; validado pelo catálogo local.</summary>
public sealed record TaskAssignment(
    string TaskId, string AttemptId, string Type, JsonElement Params, int TimeoutSeconds, DateTimeOffset LeaseExpiresAt, string Risk);

/// <summary>Resultado de uma tentativa (POST /tasks/{id}/result). Nulos são omitidos do JSON.</summary>
public sealed record TaskResultRequest(
    string AttemptId, string Status, int? ExitCode, string? Stdout, string? Stderr, bool Truncated,
    DateTimeOffset StartedAt, DateTimeOffset FinishedAt, long DurationMs, string? Error);

public sealed record TaskProgressRequest(string AttemptId, int Percent);
