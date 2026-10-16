namespace AgentToolit.Core.Execution;

/// <summary>Processo como o Agent o descreve. Campos nulos = indisponível (ex.: acesso negado sob outra conta).</summary>
public sealed record ProcessInfo(
    int Pid, string Name, string? Path, string? User, double? CpuSeconds, double WorkingSetMb, DateTimeOffset? StartedAt);

/// <summary>Abstração sobre <see cref="System.Diagnostics.Process"/> (fakes nos testes).</summary>
public interface IProcessApi
{
    IReadOnlyList<ProcessInfo> List();

    /// <summary><c>null</c> se não existe processo com esse PID.</summary>
    ProcessInfo? Get(int pid);

    /// <summary>Encerra só o processo (não a árvore). Lança se não conseguir.</summary>
    void Kill(int pid);

    /// <summary>
    /// Processo marcado como crítico pelo Windows (encerrá-lo causa CRITICAL_PROCESS_DIED, ex.: o svchost do
    /// DcomLaunch/RpcSs). <c>null</c> = não foi possível verificar (acesso negado ou processo inexistente).
    /// </summary>
    bool? IsCritical(int pid);
}
