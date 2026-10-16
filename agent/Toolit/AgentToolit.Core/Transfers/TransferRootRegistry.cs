using AgentToolit.Core.Config;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Transfers;

/// <summary>
/// Conjunto FIXO de raízes de transferência seguras, calculado UMA vez na subida do serviço. Os handlers usam só isto
/// (nunca recomputam das configurações): uma raiz que falhou a preparação (ACL) ou a conferência de segurança
/// (dono/DACL) é EXCLUÍDA — assim uma raiz gravável por usuário comum, ou cujo ACL não pôde ser aplicado, não é usada.
/// Em processo elevado/SYSTEM a ACL é aplicada e conferida pelo handle; sem elevação (dev/teste) as raízes são criadas
/// mas a conferência de segurança só é dispensada para diretórios temporários explicitamente confiáveis (seam de teste).
/// </summary>
public sealed class TransferRootRegistry
{
    /// <summary>Raízes de ENVIO (gravação): subconjunto seguro de <see cref="AgentSettings.TransferRoots"/>.</summary>
    public IReadOnlyList<string> SendRoots { get; }

    /// <summary>Raízes de RECUPERAÇÃO (leitura): subconjunto seguro de TransferRoots ∪ TransferFetchOnlyRoots.</summary>
    public IReadOnlyList<string> FetchRoots { get; }

    /// <summary>Seam de teste: raízes sob estes prefixos dispensam a conferência de segurança em processo NÃO elevado.</summary>
    internal static readonly AsyncLocal<IReadOnlyList<string>?> TrustedRootsForTests = new();

    public TransferRootRegistry(IReadOnlyList<string> sendRoots, IReadOnlyList<string> fetchRoots)
    {
        SendRoots = sendRoots;
        FetchRoots = fetchRoots;
    }

    /// <summary>
    /// Calcula as raízes seguras a partir das configurações: filtra sintaxe (PathGuard), cria as que faltam, aplica a
    /// ACL restrita pelo handle (só sob <paramref name="ownBase"/> e quando elevado), confere a segurança de todas e
    /// exclui as que falham. Nunca lança.
    /// </summary>
    public static TransferRootRegistry Build(AgentSettings settings, ILogger logger, string? ownBase = null)
    {
        if (!PathGuard.NormalizationAvailable)
        {
            logger.LogWarning("Normalização Unicode indisponível: transferências desligadas (nenhuma raiz utilizável).");

            return new TransferRootRegistry([], []);
        }

        ownBase ??= Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Toolit");
        var elevated = AgentPaths.IsElevated();

        var sendCandidates = Usable(settings.TransferRoots, logger);
        var fetchCandidates = Usable(settings.TransferRoots.Concat(settings.TransferFetchOnlyRoots), logger);

        var safe = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var root in sendCandidates.Concat(fetchCandidates).Distinct(StringComparer.OrdinalIgnoreCase))
        {
            if (PrepareAndCheck(root, ownBase, elevated, logger))
            {
                safe.Add(root);
            }
        }

        return new TransferRootRegistry(
            sendCandidates.Where(safe.Contains).ToList(),
            fetchCandidates.Where(safe.Contains).ToList());
    }

    /// <summary>Restringe a pasta-mãe (%ProgramData%\Toolit) pelo handle, uma vez na subida. Só quando elevado. Nunca lança.</summary>
    public static void RestrictParent(string parent, ILogger logger)
    {
        if (!AgentPaths.IsElevated())
        {
            return;
        }
        try
        {
            Directory.CreateDirectory(parent);
            using var handle = TransferAcl.OpenDirectory(parent, forWrite: true, out var openError);
            if (handle is null)
            {
                logger.LogWarning("Pasta base {Parent} não restringida ({Error}).", parent, openError);

                return;
            }
            if (!TransferAcl.ApplyRestricted(handle))
            {
                logger.LogWarning("Pasta base {Parent}: não foi possível aplicar a ACL restrita.", parent);
            }
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Falha ao restringir a pasta base {Parent}.", parent);
        }
    }

    private static List<string> Usable(IEnumerable<string> roots, ILogger logger) =>
        PathGuard.UsableRoots(roots, (root, reason) => logger.LogWarning("Pasta de transferência ignorada ({Reason}): {Root}", reason, root))
            .Distinct(StringComparer.OrdinalIgnoreCase).ToList();

    private static bool PrepareAndCheck(string root, string ownBase, bool elevated, ILogger logger)
    {
        try
        {
            if (PathGuard.ValidateOnDisk(root, [root]) is { } diskReason)
            {
                logger.LogError("Pasta de transferência {Root} excluída: {Reason}", root, diskReason);

                return false;
            }

            var existed = Directory.Exists(root);
            if (!existed)
            {
                Directory.CreateDirectory(root);
            }
            var underOwn = PathGuard.IsInside(root, [ownBase]);

            if (elevated)
            {
                using var handle = TransferAcl.OpenDirectory(root, forWrite: true, out var openError);
                if (handle is null)
                {
                    logger.LogError("Pasta de transferência {Root} excluída: não foi possível abrir ({Error}).", root, openError);

                    return false;
                }
                if ((underOwn || !existed) && !TransferAcl.ApplyRestricted(handle))
                {
                    logger.LogError("Pasta de transferência {Root} excluída: não foi possível aplicar a ACL restrita.", root);

                    return false;
                }
                if (TransferAcl.InspectSafety(handle) is { } offender)
                {
                    logger.LogError("Pasta de transferência {Root} excluída: gravável por {Principal}.", root, offender);

                    return false;
                }

                return true;
            }

            // Processo NÃO elevado (dev/teste): só dispensa a conferência para temporários confiáveis do teste.
            if (IsTrustedForTests(root))
            {
                logger.LogWarning("Pasta de transferência {Root}: conferência de segurança dispensada (temporário de teste).", root);

                return true;
            }
            using var readHandle = TransferAcl.OpenDirectory(root, forWrite: false, out var readError);
            if (readHandle is null)
            {
                logger.LogError("Pasta de transferência {Root} excluída: não foi possível abrir ({Error}).", root, readError);

                return false;
            }
            if (TransferAcl.InspectSafety(readHandle) is { } unsafePrincipal)
            {
                logger.LogError("Pasta de transferência {Root} excluída: gravável por {Principal} (ACL não aplicada: processo sem privilégio).", root, unsafePrincipal);

                return false;
            }

            return true;
        }
        catch (Exception ex)
        {
            logger.LogError(ex, "Pasta de transferência {Root} excluída: falha ao preparar.", root);

            return false;
        }
    }

    private static bool IsTrustedForTests(string root) =>
        TrustedRootsForTests.Value is { Count: > 0 } trusted && PathGuard.IsInside(root, trusted);
}
