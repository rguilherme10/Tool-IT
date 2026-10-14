using AgentToolit.Core.Config;

namespace AgentToolit.Core.Net;

/// <summary>Estado compartilhado entre os workers (identidade registrada e flag de revogação).</summary>
public sealed class AgentRuntime(AgentStateStore store)
{
    private readonly object _gate = new();
    private AgentState? _state = store.Load();

    public AgentState? State
    {
        get { lock (_gate) { return _state; } }
    }

    public bool IsEnrolled => State is { AgentId.Length: > 0 };

    public bool IsRevoked => State?.Revoked == true;

    public void SetEnrolled(AgentState state)
    {
        lock (_gate)
        {
            _state = state;
            store.Save(state);
        }
    }

    public void MarkRevoked()
    {
        lock (_gate)
        {
            if (_state is null || _state.Revoked)
            {
                return;
            }

            _state.Revoked = true;
            store.Save(_state);
        }
    }
}
