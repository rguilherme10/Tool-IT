namespace AgentToolit.Core.Transfers;

/// <summary>Destino/origem já validado: pasta e nome em NFC e o caminho completo (sempre dentro de uma raiz).</summary>
public sealed record TransferTarget(string Directory, string Name, string FullPath);

/// <summary>
/// Validação do <c>remote_path</c> vindo do Manager antes de qualquer acesso ao disco: sintaxe (PathGuard), extensão
/// (só no envio), pasta dentro de uma raiz local e conferência no disco (reparse points, forma canônica).
/// </summary>
public static class TransferPaths
{
    /// <summary>Devolve o alvo ou null com <paramref name="error"/> (mensagem curta, sem o caminho).</summary>
    public static TransferTarget? Resolve(string? remotePath, IReadOnlyList<string> roots, bool blockExtensions, out string? error)
    {
        var pathReason = remotePath is null ? PathGuard.Message("empty") : PathGuard.ValidateDirectory(remotePath);
        if (remotePath is null || pathReason is not null)
        {
            error = "Caminho inválido: " + pathReason;

            return null;
        }

        var path = remotePath;
        var cut = path.LastIndexOf('\\');   // existe: caminho válido começa com "X:\"
        var dir = cut == 2 ? path[..3] : path[..cut];
        var name = path[(cut + 1)..];

        if (PathGuard.ValidateDirectory(dir) is { } dirReason)
        {
            error = "Pasta inválida: " + dirReason;

            return null;
        }
        if (PathGuard.ValidateFileName(name) is { } nameReason)
        {
            error = "Nome de arquivo inválido: " + nameReason;

            return null;
        }
        if (name.StartsWith(".toolit-", StringComparison.OrdinalIgnoreCase))
        {
            error = "Nome de arquivo reservado para arquivos parciais de transferência.";

            return null;
        }
        if (blockExtensions && PathGuard.HasBlockedExtension(name))
        {
            error = "Extensão de arquivo bloqueada (executáveis e scripts nunca são transferidos).";

            return null;
        }

        string full, normalizedDir, normalizedName;
        try
        {
            normalizedDir = PathGuard.NormalizeName(dir);
            normalizedName = PathGuard.NormalizeName(name);
            full = PathGuard.Join(normalizedDir, normalizedName);
        }
        catch (Exception ex) when (ex is ArgumentException or InvalidOperationException)
        {
            error = ex.Message;

            return null;
        }

        if (!PathGuard.IsInside(normalizedDir, roots))
        {
            error = PathGuard.Message("outside_roots");

            return null;
        }
        if (PathGuard.ValidateOnDisk(full, roots) is { } diskReason)
        {
            error = diskReason;

            return null;
        }

        error = null;

        return new TransferTarget(normalizedDir, normalizedName, full);
    }

    /// <summary>Códigos de <see cref="PathGuard.OpenChecked"/> que indicam ataque/corrida (registrar como Warning).</summary>
    public static bool IsSuspicious(string? code) =>
        code is "reparse_race" or "reparse_race_cleanup_failed" or "hard_link" or "reparse_point" or "outside_roots";
}
