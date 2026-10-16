using System.Text.Json;

namespace AgentToolit.Core.Execution;

/// <summary>
/// Um tipo do catálogo local. Recebe parâmetros JÁ validados por <see cref="TaskSchemaValidator"/>.
/// Nunca monta linha de comando: usa só APIs .NET (Process, ServiceController, Environment).
/// </summary>
public interface ITaskHandler
{
    string Type { get; }

    Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct);
}

/// <summary>Atualização de inventário sob demanda (implementada no host, que conhece os coletores).</summary>
public interface IInventoryRefresher
{
    Task RefreshAsync(CancellationToken ct);
}
