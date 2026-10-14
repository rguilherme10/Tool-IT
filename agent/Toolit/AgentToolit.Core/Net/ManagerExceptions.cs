namespace AgentToolit.Core.Net;

/// <summary>Falha transitória (rede, timeout, 5xx, 429): reenviar depois, com backoff. Nada é descartado.</summary>
public sealed class ManagerUnavailableException(string message, TimeSpan? retryAfter = null, Exception? inner = null)
    : Exception(message, inner)
{
    public TimeSpan? RetryAfter { get; } = retryAfter;
}

/// <summary>403 agent.revoked: a instalação foi revogada. O Agent deve cessar toda operação.</summary>
public sealed class AgentRevokedException() : Exception("A instalação foi revogada pelo Manager.");

/// <summary>401 auth.invalid: assinatura/relógio. <see cref="ServerTime"/> permite corrigir deslocamento de relógio.</summary>
public sealed class ManagerAuthException(DateTimeOffset? serverTime) : Exception("Autenticação recusada pelo Manager.")
{
    public DateTimeOffset? ServerTime { get; } = serverTime;
}

/// <summary>4xx definitivo (422, 403 enrollment.invalid, 404...): reenviar o mesmo conteúdo não resolve.</summary>
public sealed class ManagerRejectedException(int status, string code, string message) : Exception($"{status} {code}: {message}")
{
    public int Status { get; } = status;
    public string Code { get; } = code;
}
