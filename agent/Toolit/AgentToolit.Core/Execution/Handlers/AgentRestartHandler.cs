using System.Text.Json;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// agent.restart: não para nada aqui. Só marca o pedido; o poller envia o resultado e DEPOIS pede ao host a parada
/// com código ≠ 0 (<see cref="IAgentRestarter"/>), para o resultado não se perder.
/// </summary>
public sealed class AgentRestartHandler : ITaskHandler
{
    public string Type => "agent.restart";

    public Task<TaskOutcome> ExecuteAsync(JsonElement parameters, CancellationToken ct) =>
        Task.FromResult(TaskOutcome.Completed("Reinício do Agent agendado após o envio deste resultado.") with { RestartAgent = true });
}
