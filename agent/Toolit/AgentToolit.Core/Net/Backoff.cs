namespace AgentToolit.Core.Net;

/// <summary>Backoff exponencial com jitter de ±20 % (docs/api/agent-api-v1.md §2: 5 s → 15 min).</summary>
public sealed class Backoff(TimeSpan? min = null, TimeSpan? max = null, Random? random = null)
{
    private readonly TimeSpan _min = min ?? TimeSpan.FromSeconds(5);
    private readonly TimeSpan _max = max ?? TimeSpan.FromMinutes(15);
    private readonly Random _random = random ?? Random.Shared;
    private int _attempt;

    public int Attempt => _attempt;

    public TimeSpan Next()
    {
        var exponent = Math.Min(_attempt, 20);
        _attempt++;
        var baseMs = Math.Min(_max.TotalMilliseconds, _min.TotalMilliseconds * Math.Pow(2, exponent));
        var jitter = 1 + ((_random.NextDouble() * 0.4) - 0.2);

        return TimeSpan.FromMilliseconds(baseMs * jitter);
    }

    public void Reset() => _attempt = 0;
}
