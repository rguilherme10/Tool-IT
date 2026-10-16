namespace AgentToolit.Core.Execution;

/// <summary>Nomes de processo de antivírus registrados (no host: root\SecurityCenter2 via WMI). Pode lançar: o chamador trata.</summary>
public interface IProtectedProcessSource
{
    IReadOnlyList<string> GetProcessNames();
}

/// <summary>
/// Processos que o Agent NUNCA encerra, mesmo com aprovação do Manager: núcleo do Windows, o próprio Agent
/// e os antivírus registrados. Comparação por nome normalizado (sem ".exe", sem diferenciar maiúsculas).
/// </summary>
public sealed class ProtectedProcessList
{
    private static readonly HashSet<string> Fixed = new(StringComparer.OrdinalIgnoreCase)
    {
        "System", "csrss", "wininit", "winlogon", "lsass", "services", "smss",
        // Pseudo-processos do kernel e o isolamento do LSA: encerrá-los derruba ou trava o sistema.
        "Idle", "Registry", "Memory Compression", "Secure System", "LsaIso",
        // Microsoft Defender: o SecurityCenter2 informa um caminho "windowsdefender://", não o executável.
        "MsMpEng", "NisSrv", "MpDefenderCoreService",
    };

    private static readonly TimeSpan AntivirusCacheFor = TimeSpan.FromMinutes(10);

    private readonly IProtectedProcessSource? _source;
    private readonly int _selfPid;
    private readonly object _gate = new();
    private HashSet<string> _antivirus = new(StringComparer.OrdinalIgnoreCase);
    private DateTimeOffset _antivirusLoadedAt = DateTimeOffset.MinValue;

    public ProtectedProcessList(IProtectedProcessSource? source = null, int? selfPid = null)
    {
        _source = source;
        _selfPid = selfPid ?? Environment.ProcessId;
    }

    public static string Normalize(string name)
    {
        var trimmed = name.Trim();

        return trimmed.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) ? trimmed[..^4].TrimEnd() : trimmed;
    }

    public bool IsProtected(string name, int pid)
    {
        if (pid == _selfPid || pid is >= 0 and < 5)
        {
            return true;
        }

        var normalized = Normalize(name);

        return Fixed.Contains(normalized) || Antivirus().Contains(normalized);
    }

    /// <summary>Consulta (com cache) os antivírus registrados. Falha na consulta → lista vazia; nunca lança.</summary>
    private HashSet<string> Antivirus()
    {
        if (_source is null)
        {
            return _antivirus;
        }

        lock (_gate)
        {
            if (DateTimeOffset.UtcNow - _antivirusLoadedAt < AntivirusCacheFor)
            {
                return _antivirus;
            }

            _antivirusLoadedAt = DateTimeOffset.UtcNow;
            try
            {
                _antivirus = new HashSet<string>(
                    _source.GetProcessNames().Where(n => !string.IsNullOrWhiteSpace(n)).Select(Normalize),
                    StringComparer.OrdinalIgnoreCase);
            }
            catch (Exception)
            {
                // WMI indisponível/sem acesso: segue só com a lista fixa (que já cobre o Defender).
                _antivirus = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            }

            return _antivirus;
        }
    }
}
