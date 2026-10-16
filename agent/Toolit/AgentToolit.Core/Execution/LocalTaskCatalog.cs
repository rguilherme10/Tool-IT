namespace AgentToolit.Core.Execution;

/// <summary>
/// Catálogo local, espelho do <c>App\Support\Tasks\TaskCatalog</c> do Manager. É a autoridade final no Agent:
/// tipo fora daqui é recusado mesmo que o Manager diga que foi aprovado.
/// </summary>
public static class LocalTaskCatalog
{
    // Timeouts locais (s): limite superior, mesmo que o Manager peça mais.
    private static readonly Dictionary<string, int> TimeoutSeconds = new(StringComparer.Ordinal)
    {
        ["system.info"] = 30,
        ["process.list"] = 30,
        ["service.list"] = 30,
        ["inventory.refresh"] = 120,
        ["service.start"] = 60,
        ["service.stop"] = 60,
        ["service.restart"] = 60,
        ["process.kill"] = 15,
        ["agent.restart"] = 30,
    };

    /// <summary>Mesmos padrões de <c>toolit.tasks.service_protected</c> no Manager: nunca parar nem reiniciar.</summary>
    private static readonly HashSet<string> ProtectedServices = new(StringComparer.OrdinalIgnoreCase)
    {
        "RpcSs", "DcomLaunch", "LSM", "SamSs", "EventLog", "BFE", "mpssvc", "WinDefend", "CryptSvc", "Winmgmt", "ToolitAgent",
    };

    public static IReadOnlyCollection<string> Types => TimeoutSeconds.Keys;

    public static bool IsKnown(string? type) => type is not null && TimeoutSeconds.ContainsKey(type);

    public static TimeSpan Timeout(string type) => TimeSpan.FromSeconds(TimeoutSeconds[type]);

    /// <summary>
    /// Timeout efetivo: o menor entre o pedido pelo Manager e o local (pedido ausente/ inválido → local).
    /// </summary>
    public static TimeSpan EffectiveTimeout(string type, int requestedSeconds)
    {
        var local = TimeoutSeconds[type];

        return TimeSpan.FromSeconds(requestedSeconds > 0 ? Math.Min(requestedSeconds, local) : local);
    }

    public static bool IsProtectedService(string name) => ProtectedServices.Contains(name.Trim());
}
