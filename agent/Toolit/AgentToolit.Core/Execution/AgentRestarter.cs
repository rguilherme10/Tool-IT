using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Execution;

/// <summary>
/// Para o host com código de saída ≠ 0: o serviço está configurado com <c>sc failure ... restart</c> e
/// <c>failureflag 1</c>, então o SCM o reinicia. (Comportamento real só validável instalado como Administrador — L8.)
/// </summary>
public sealed class AgentRestarter(IHostApplicationLifetime lifetime, ILogger<AgentRestarter> logger, Action<int>? setExitCode = null)
    : IAgentRestarter
{
    public const int RestartExitCode = 75;   // ≠ 0: sinaliza falha ao SCM para disparar a recuperação

    private readonly Action<int> _setExitCode = setExitCode ?? (code => Environment.ExitCode = code);

    public void RequestRestart()
    {
        logger.LogWarning("Parando o Agent com código {Code} para reinício pela recuperação do serviço.", RestartExitCode);
        _setExitCode(RestartExitCode);
        lifetime.StopApplication();
    }
}
