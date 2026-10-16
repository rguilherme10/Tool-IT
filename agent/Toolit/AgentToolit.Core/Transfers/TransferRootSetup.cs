using System.Collections.Concurrent;
using AgentToolit.Core.Config;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Transfers;

/// <summary>
/// Raízes locais de transferência (veto do Agent): quais valem para cada direção e a preparação no disco com ACL
/// restrita (SYSTEM + Administradores, sem herança) — para que um usuário comum não consiga plantar junction/hard link
/// dentro de uma raiz.
/// </summary>
public static class TransferRootSetup
{
    private static readonly ConcurrentDictionary<string, byte> LoggedInvalid = new(StringComparer.Ordinal);

    /// <summary>Raízes do envio (gravação): só <see cref="AgentSettings.TransferRoots"/> válidas.</summary>
    public static IReadOnlyList<string> SendRoots(AgentSettings settings, ILogger logger) => Usable(settings.TransferRoots, logger);

    /// <summary>Raízes da recuperação (leitura): <see cref="AgentSettings.TransferRoots"/> ∪ <see cref="AgentSettings.TransferFetchOnlyRoots"/>.</summary>
    public static IReadOnlyList<string> FetchRoots(AgentSettings settings, ILogger logger) =>
        Usable(settings.TransferRoots.Concat(settings.TransferFetchOnlyRoots), logger);

    private static List<string> Usable(IEnumerable<string> roots, ILogger logger) =>
        PathGuard.UsableRoots(roots, (root, reason) =>
        {
            if (LoggedInvalid.TryAdd(root, 0))   // uma vez por processo
            {
                logger.LogWarning("Pasta de transferência ignorada na configuração ({Reason}): {Root}", reason, root);
            }
        }).Distinct(StringComparer.OrdinalIgnoreCase).ToList();

    /// <summary>
    /// Na subida do serviço: cria as raízes que faltam já com a ACL restrita e reaplica a ACL nas que existem dentro
    /// de <paramref name="ownBase"/> (padrão %ProgramData%\Toolit, o espaço do próprio Toolit). Raiz existente fora
    /// dele é do administrador: a ACL não é mudada (só um aviso). Sem elevação (SYSTEM/Administrador) nenhuma ACL é
    /// aplicada (registrado). Nunca lança: devolve as raízes preparadas.
    /// </summary>
    public static IReadOnlyList<string> Prepare(AgentSettings settings, ILogger logger, string? ownBase = null)
    {
        if (!PathGuard.NormalizationAvailable)
        {
            logger.LogWarning("Normalização Unicode indisponível: pastas de transferência não preparadas (transferências desligadas).");

            return [];
        }

        ownBase ??= Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Toolit");
        var prepared = new List<string>();
        foreach (var root in FetchRoots(settings, logger))
        {
            try
            {
                if (PathGuard.ValidateOnDisk(root, [root]) is { } reason)
                {
                    logger.LogWarning("Pasta de transferência {Root} não preparada: {Reason}", root, reason);
                    continue;
                }

                if (!Directory.Exists(root) || PathGuard.IsInside(root, [ownBase]))
                {
                    var applied = CreateRestricted(root);
                    if (applied)
                    {
                        logger.LogInformation("Pasta de transferência {Root} pronta (acesso só para SYSTEM e Administradores).", root);
                    }
                    else
                    {
                        logger.LogWarning("Pasta de transferência {Root} pronta, mas SEM a ACL restrita (processo não elevado).", root);
                    }
                }
                else
                {
                    logger.LogWarning("Pasta de transferência {Root} já existia fora de {Base}: ACL mantida. Garanta que só SYSTEM e Administradores possam gravar nela.", root, ownBase);
                }

                prepared.Add(root);
            }
            catch (Exception ex)   // a subida do serviço nunca cai por causa disto
            {
                logger.LogError(ex, "Não foi possível preparar a pasta de transferência {Root}.", root);
            }
        }

        return prepared;
    }

    /// <summary>
    /// Garante <paramref name="dir"/> com a ACL de <see cref="AgentPaths.RestrictedSecurity"/>. Elevado (SYSTEM ou
    /// Administrador): cria já com a ACL (sem janela com a herdada) ou a reaplica, e passa o dono a Administradores.
    /// Não elevado: só cria (um usuário comum se trancaria para fora). Devolve true se a ACL foi aplicada.
    /// </summary>
    public static bool CreateRestricted(string dir)
    {
        if (!AgentPaths.IsElevated())
        {
            Directory.CreateDirectory(dir);

            return false;
        }

        var info = new DirectoryInfo(dir);
        if (!info.Exists)
        {
            if (info.Parent is { } parent)
            {
                Directory.CreateDirectory(parent.FullName);
            }
            info.Create(AgentPaths.RestrictedSecurity());
        }

        var security = AgentPaths.RestrictedSecurity();
        security.SetOwner(new System.Security.Principal.SecurityIdentifier(System.Security.Principal.WellKnownSidType.BuiltinAdministratorsSid, null));
        info.SetAccessControl(security);

        return true;
    }
}
