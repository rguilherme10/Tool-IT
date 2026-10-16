using AgentToolit.Collectors;
using AgentToolit.Collectors.Wmi;
using AgentToolit.Core.Execution;

namespace AgentToolit;

/// <summary>inventory.refresh → coleta normal de inventário (o Core não referencia os coletores).</summary>
public sealed class CollectionInventoryRefresher(CollectionService collection) : IInventoryRefresher
{
    public Task RefreshAsync(CancellationToken ct) => collection.CollectInventoryAsync(ct);
}

/// <summary>
/// Antivírus registrados no Windows Security Center (root\SecurityCenter2, só em estações). Devolve os nomes dos
/// executáveis informados; qualquer falha (servidor sem SecurityCenter2, acesso negado, timeout) → lista vazia.
/// </summary>
public sealed class SecurityCenterAntivirusSource(IWmiQuery wmi, ILogger<SecurityCenterAntivirusSource> logger) : IProtectedProcessSource
{
    public IReadOnlyList<string> GetProcessNames()
    {
        try
        {
            var rows = wmi.Query(@"root\SecurityCenter2", "SELECT displayName, pathToSignedProductExe, pathToSignedReportingExe FROM AntiVirusProduct",
                TimeSpan.FromSeconds(10));

            return rows
                .SelectMany(r => new[] { r.GetValueOrDefault("pathToSignedProductExe") as string, r.GetValueOrDefault("pathToSignedReportingExe") as string })
                .Select(ExeName)
                .Where(n => n is not null)
                .Select(n => n!)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToList();
        }
        catch (Exception ex)
        {
            logger.LogDebug(ex, "Antivírus registrados indisponíveis; usando só a lista fixa de processos protegidos.");

            return [];
        }
    }

    private static string? ExeName(string? path)
    {
        if (string.IsNullOrWhiteSpace(path) || path.Contains("://", StringComparison.Ordinal))
        {
            return null;   // ex.: "windowsdefender://" (o Defender já está na lista fixa)
        }

        var name = Path.GetFileNameWithoutExtension(Environment.ExpandEnvironmentVariables(path.Trim().Trim('"')));

        return string.IsNullOrWhiteSpace(name) ? null : name;
    }
}
