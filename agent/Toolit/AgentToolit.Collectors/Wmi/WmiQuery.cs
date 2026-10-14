using System.Management;

namespace AgentToolit.Collectors.Wmi;

public interface IWmiQuery
{
    /// <summary>Consulta WQL com propriedades explícitas (nunca SELECT *). Lança em timeout/acesso negado.</summary>
    IReadOnlyList<IReadOnlyDictionary<string, object?>> Query(string scope, string wql, TimeSpan timeout);
}

/// <summary>WMI com timeout por consulta. Consultas pontuais e de poucas propriedades; sem Win32_Product (dispara reconfiguração MSI).</summary>
public sealed class WmiQuery : IWmiQuery
{
    public IReadOnlyList<IReadOnlyDictionary<string, object?>> Query(string scope, string wql, TimeSpan timeout)
    {
        var options = new System.Management.EnumerationOptions { Timeout = timeout, ReturnImmediately = true, Rewindable = false };
        using var searcher = new ManagementObjectSearcher(scope, wql, options);
        var rows = new List<IReadOnlyDictionary<string, object?>>();

        foreach (ManagementBaseObject item in searcher.Get())
        {
            using (item)
            {
                var row = new Dictionary<string, object?>(StringComparer.OrdinalIgnoreCase);
                foreach (var property in item.Properties)
                {
                    row[property.Name] = property.Value;
                }

                rows.Add(row);
            }
        }

        return rows;
    }
}

internal static class WmiValue
{
    public static string? Str(this IReadOnlyDictionary<string, object?> row, string key) =>
        row.TryGetValue(key, out var v) && v is not null ? Clean(v.ToString()) : null;

    public static long? Long(this IReadOnlyDictionary<string, object?> row, string key) =>
        row.TryGetValue(key, out var v) && v is not null && long.TryParse(v.ToString(), out var n) ? n : null;

    public static bool? Bool(this IReadOnlyDictionary<string, object?> row, string key) =>
        row.TryGetValue(key, out var v) && v is bool b ? b : null;

    /// <summary>Remove caracteres de controle e limita o tamanho: dado do equipamento nunca é confiável.</summary>
    public static string? Clean(string? value, int max = 255)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        var cleaned = new string(value.Where(c => !char.IsControl(c)).ToArray()).Trim();

        return cleaned.Length == 0 ? null : cleaned.Length > max ? cleaned[..max] : cleaned;
    }
}
