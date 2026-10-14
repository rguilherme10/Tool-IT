using System.Globalization;
using Microsoft.Win32;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Collectors.Inventory;

/// <summary>Uma chave "Uninstall" lida do Registro. <see cref="Source"/>: HKLM64, HKLM32 ou HKU:&lt;sid&gt;.</summary>
public sealed record UninstallEntry(string Source, IReadOnlyDictionary<string, object?> Values);

public interface IUninstallSource
{
    IEnumerable<UninstallEntry> Read();
}

/// <summary>
/// Fonte documentada do Windows para "Programas e Recursos": chaves Uninstall em HKLM (64/32 bits) e nos hives de
/// usuários carregados. Apenas LÊ valores — nunca executa UninstallString nem scripts de programas.
/// </summary>
public sealed class RegistryUninstallSource : IUninstallSource
{
    private const string Path = @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall";
    private static readonly string[] Wanted = ["DisplayName", "DisplayVersion", "Publisher", "InstallDate", "SystemComponent", "ParentKeyName", "ParentDisplayName", "ReleaseType", "WindowsInstaller"];

    public IEnumerable<UninstallEntry> Read()
    {
        foreach (var (view, tag) in new[] { (RegistryView.Registry64, "HKLM64"), (RegistryView.Registry32, "HKLM32") })
        {
            using var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view);
            foreach (var entry in Enumerate(baseKey, Path, tag))
            {
                yield return entry;
            }
        }

        using var users = RegistryKey.OpenBaseKey(RegistryHive.Users, RegistryView.Registry64);
        foreach (var sid in users.GetSubKeyNames().Where(s => s.StartsWith("S-1-5-21-", StringComparison.Ordinal) && !s.EndsWith("_Classes", StringComparison.Ordinal)))
        {
            foreach (var entry in Enumerate(users, $@"{sid}\{Path}", "HKU"))
            {
                yield return entry;
            }
        }
    }

    private static IEnumerable<UninstallEntry> Enumerate(RegistryKey root, string path, string source)
    {
        using var key = root.OpenSubKey(path);
        if (key is null)
        {
            yield break;
        }

        foreach (var name in key.GetSubKeyNames())
        {
            Dictionary<string, object?> values;
            try
            {
                using var sub = key.OpenSubKey(name);
                if (sub is null)
                {
                    continue;
                }

                values = Wanted.ToDictionary(w => w, w => sub.GetValue(w), StringComparer.OrdinalIgnoreCase);
            }
            catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException or IOException)
            {
                continue;
            }

            yield return new UninstallEntry(source, values);
        }
    }
}

/// <summary>Transforma entradas cruas em itens normalizados e deduplicados. Puro: testável sem Registro.</summary>
public static class SoftwareParser
{
    public const int MaxItems = 5000;

    public sealed record Item(string Name, string? Version, string? Publisher, string? InstallDate, string? Arch, string Source);

    public static IReadOnlyList<Item> Parse(IEnumerable<UninstallEntry> entries)
    {
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var items = new List<Item>();

        foreach (var entry in entries)
        {
            var v = entry.Values;
            var name = Text(v, "DisplayName", 255);

            // Sem nome, componentes de sistema e atualizações (têm pai) não são "software instalado" para o inventário.
            if (name is null || Int(v, "SystemComponent") == 1 || Text(v, "ParentKeyName", 255) is not null
                || Text(v, "ParentDisplayName", 255) is not null || Text(v, "ReleaseType", 64) is "Update" or "Security Update" or "Hotfix")
            {
                continue;
            }

            var version = Text(v, "DisplayVersion", 64);
            var arch = entry.Source switch { "HKLM64" => "x64", "HKLM32" => "x86", _ => null };
            if (!seen.Add($"{name}|{version}|{arch}|{entry.Source}"))
            {
                continue;
            }

            items.Add(new Item(name, version, Text(v, "Publisher", 255), InstallDate(v), arch, entry.Source));
            if (items.Count >= MaxItems)
            {
                break;
            }
        }

        return items.OrderBy(i => i.Name, StringComparer.OrdinalIgnoreCase).ToList();
    }

    /// <summary>InstallDate no Registro vem como "yyyyMMdd" (string) — outros formatos são ignorados.</summary>
    public static string? InstallDate(IReadOnlyDictionary<string, object?> values) =>
        values.TryGetValue("InstallDate", out var raw) && raw?.ToString() is { Length: 8 } s
        && DateTime.TryParseExact(s, "yyyyMMdd", CultureInfo.InvariantCulture, DateTimeStyles.None, out var d)
            ? d.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture) : null;

    private static string? Text(IReadOnlyDictionary<string, object?> v, string key, int max)
    {
        if (!v.TryGetValue(key, out var raw) || raw is null)
        {
            return null;
        }

        var cleaned = new string(raw.ToString()!.Where(c => !char.IsControl(c)).ToArray()).Trim();

        return cleaned.Length == 0 ? null : cleaned.Length > max ? cleaned[..max] : cleaned;
    }

    private static int? Int(IReadOnlyDictionary<string, object?> v, string key) =>
        v.TryGetValue(key, out var raw) && raw is not null && int.TryParse(raw.ToString(), out var n) ? n : null;
}

public sealed class SoftwareCollector(IUninstallSource source) : ISectionCollector
{
    public string Name => "software";

    public SectionResult Collect(CancellationToken ct)
    {
        var items = SoftwareParser.Parse(source.Read());

        return SectionResult.Success(new
        {
            items = items.Select(i => new { name = i.Name, version = i.Version, publisher = i.Publisher, install_date = i.InstallDate, arch = i.Arch, source = i.Source }),
            truncated = items.Count >= SoftwareParser.MaxItems,
        });
    }
}
