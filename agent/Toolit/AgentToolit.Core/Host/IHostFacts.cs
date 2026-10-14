namespace AgentToolit.Core.Host;

/// <summary>Fatos baratos do equipamento usados no heartbeat (implementação Windows em AgentToolit.Collectors).</summary>
public interface IHostFacts
{
    long UptimeSeconds { get; }

    /// <summary>Usuário interativo atual (console), quando o Windows informa; nulo se ninguém está conectado.</summary>
    string? GetInteractiveUser();

    IReadOnlyList<string> GetIpAddresses();
}
