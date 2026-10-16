using System.Collections.Concurrent;

namespace AgentToolit.Core.Execution;

/// <summary>
/// Ponte entre o heartbeat e o poller: <see cref="Notify"/> acorda a espera do poller (tasks_available) e
/// <see cref="Cancel"/> cancela a tarefa em execução cujo id veio em cancel_task_ids.
/// </summary>
public sealed class TaskCoordinator(TimeProvider? time = null)
{
    private readonly TimeProvider _time = time ?? TimeProvider.System;
    private readonly SemaphoreSlim _signal = new(0, 1);
    private readonly ConcurrentDictionary<string, CancellationTokenSource> _running = new(StringComparer.Ordinal);

    /// <summary>Sinaliza que há tarefa. Sem ninguém esperando, o sinal fica guardado (no máximo um).</summary>
    public void Notify()
    {
        lock (_signal)
        {
            if (_signal.CurrentCount == 0)
            {
                _signal.Release();
            }
        }
    }

    /// <summary>Espera <paramref name="delay"/> ou até um <see cref="Notify"/>, o que vier primeiro. Lança se <paramref name="ct"/> cancelar.</summary>
    public async Task WaitAsync(TimeSpan delay, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (delay <= TimeSpan.Zero)
        {
            return;
        }

        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct);
        var signalled = _signal.WaitAsync(linked.Token);
        var elapsed = Task.Delay(delay, _time, linked.Token);
        await Task.WhenAny(signalled, elapsed);
        linked.Cancel();   // libera a espera que perdeu (o SemaphoreSlim não consome sinal de espera cancelada)

        try
        {
            await signalled;
        }
        catch (OperationCanceledException)
        {
            // espera do sinal cancelada: venceu o intervalo (ou o host está parando, tratado abaixo)
        }

        ct.ThrowIfCancellationRequested();
    }

    /// <summary>Registra a tarefa em execução; o retorno remove o registro.</summary>
    public IDisposable Register(string taskId, CancellationTokenSource cts)
    {
        _running[taskId] = cts;

        return new Registration(this, taskId, cts);
    }

    public void Cancel(IEnumerable<string>? ids)
    {
        foreach (var id in ids ?? [])
        {
            if (id is not null && _running.TryGetValue(id, out var cts))
            {
                try
                {
                    cts.Cancel();
                }
                catch (ObjectDisposedException)
                {
                    // a tarefa terminou entre a busca e o cancelamento
                }
            }
        }
    }

    private sealed class Registration(TaskCoordinator owner, string taskId, CancellationTokenSource cts) : IDisposable
    {
        public void Dispose() => owner._running.TryRemove(new KeyValuePair<string, CancellationTokenSource>(taskId, cts));
    }
}
