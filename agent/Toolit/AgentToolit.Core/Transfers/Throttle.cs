namespace AgentToolit.Core.Transfers;

/// <summary>
/// Limitador de banda (balde de fichas) em bytes por segundo, pelo relógio injetado. Começa com 1 s de crédito e
/// nunca acumula mais que isso (ociosidade não vira rajada longa). Quem já transferiu <c>n</c> bytes chama
/// <see cref="WaitAsync"/>: se a conta ficou negativa, espera exatamente o tempo para quitá-la — nunca mais.
/// Respeita o cancelamento. Uma instância por transferência (não é thread-safe).
/// </summary>
public sealed class Throttle
{
    private readonly double _bytesPerSecond;
    private readonly TimeProvider _time;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private double _tokens;
    private DateTimeOffset _last;

    /// <param name="delay">Espera assíncrona (padrão: <see cref="Task.Delay(TimeSpan, TimeProvider, CancellationToken)"/>); injetável nos testes.</param>
    public Throttle(long bytesPerSecond, TimeProvider time, Func<TimeSpan, CancellationToken, Task>? delay = null)
    {
        ArgumentOutOfRangeException.ThrowIfNegativeOrZero(bytesPerSecond);
        _bytesPerSecond = bytesPerSecond;
        _time = time;
        _delay = delay ?? ((d, ct) => Task.Delay(d, time, ct));
        _tokens = bytesPerSecond;
        _last = time.GetUtcNow();
    }

    /// <summary>Registra <paramref name="bytes"/> transferidos e espera o necessário para manter a média.</summary>
    public async Task WaitAsync(long bytes, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();

        var now = _time.GetUtcNow();
        var elapsed = Math.Max(0, (now - _last).TotalSeconds);
        _last = now;
        _tokens = Math.Min(_bytesPerSecond, _tokens + elapsed * _bytesPerSecond) - Math.Max(0, bytes);

        if (_tokens < 0)
        {
            await _delay(TimeSpan.FromSeconds(-_tokens / _bytesPerSecond), ct);
        }
    }
}
