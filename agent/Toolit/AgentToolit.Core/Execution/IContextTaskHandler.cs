using System.Text.Json;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Core.Execution;

/// <summary>
/// Contexto da tentativa em execução. <see cref="ReportProgress"/> envia o percentual (0–99) ao Manager, o que renova
/// a lease; nunca lança (falhas são registradas e engolidas pelo <see cref="TaskRunner"/>) — o progresso não é
/// necessário para a correção. Se o Manager disser que a tentativa já não vale (409), o runner cancela o token do handler.
/// </summary>
public sealed record TaskContext(string TaskId, string AttemptId, Func<int, CancellationToken, Task> ReportProgress)
{
    /// <summary>Contexto sem envio de progresso (handler chamado pela interface antiga).</summary>
    public static TaskContext None { get; } = new("", "", (_, _) => Task.CompletedTask);
}

/// <summary>Handler que recebe o <see cref="TaskContext"/> (progresso). O runner prefere esta sobrecarga.</summary>
public interface IContextTaskHandler : ITaskHandler
{
    Task<TaskOutcome> ExecuteAsync(JsonElement parameters, TaskContext context, CancellationToken ct);
}

/// <summary>Destino do progresso (POST /tasks/{id}/progress). Pode lançar: o runner trata.</summary>
public interface ITaskProgressSink
{
    Task ReportAsync(string taskId, string attemptId, int percent, CancellationToken ct);
}

/// <summary>Progresso pelo <see cref="ManagerClient.ProgressAsync"/> (409 task.conflict sobe como <see cref="ManagerRejectedException"/>).</summary>
public sealed class ManagerProgressSink(ManagerClient client) : ITaskProgressSink
{
    public Task ReportAsync(string taskId, string attemptId, int percent, CancellationToken ct) =>
        client.ProgressAsync(taskId, new TaskProgressRequest(attemptId, percent), ct);
}
