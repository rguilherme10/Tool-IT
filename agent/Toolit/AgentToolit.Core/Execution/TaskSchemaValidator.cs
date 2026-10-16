using System.Text.Json;
using System.Text.RegularExpressions;

namespace AgentToolit.Core.Execution;

/// <summary>
/// Schema estrito dos parâmetros, com as MESMAS regras do <c>TaskCatalog::validate</c> do Manager:
/// tipos JSON exatos (sem coerção), nenhuma chave extra, nome de serviço por regex e <c>pid ≥ 5</c> inteiro.
/// O Agent revalida tudo: o Manager pode estar comprometido ou desatualizado.
/// </summary>
public static partial class TaskSchemaValidator
{
    // \z (e não $): em .NET "$" também aceitaria um "\n" final, como no PHP. Só ASCII (sem RegexOptions.IgnoreCase).
    [GeneratedRegex(@"^[A-Za-z0-9_.$-]{1,128}\z", RegexOptions.CultureInvariant)]
    private static partial Regex ServiceNamePattern();

    // Dígitos decimais apenas: recusa "12.0", "1e3", "-1" e strings (o PHP faz o mesmo com is_int).
    [GeneratedRegex(@"^[0-9]{1,10}\z", RegexOptions.CultureInvariant)]
    private static partial Regex PidPattern();

    // UUID canônico em minúsculas (o mesmo UUID_PATTERN do Manager): sem chaves, sem maiúsculas, sem espaços.
    [GeneratedRegex(@"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z", RegexOptions.CultureInvariant)]
    private static partial Regex UuidPattern();

    private static readonly string[] NoKeys = [];
    private static readonly string[] ServiceKeys = ["name"];
    private static readonly string[] KillKeys = ["pid", "name"];
    private static readonly string[] FileKeys = ["transfer_id"];

    private static bool IsServiceType(string type) => type is "service.start" or "service.stop" or "service.restart";

    private static bool IsFileType(string type) => type is "file.send" or "file.fetch";

    /// <summary>UUID canônico minúsculo (formato de <c>transfer_id</c>).</summary>
    public static bool IsCanonicalUuid(string? value) => value is not null && UuidPattern().IsMatch(value);

    public static (bool Ok, string? Error) Validate(string type, JsonElement p)
    {
        if (!LocalTaskCatalog.IsKnown(type))
        {
            return (false, "unknown task type");
        }

        if (p.ValueKind != JsonValueKind.Object)
        {
            return (false, "params must be an object");
        }

        var allowed = IsServiceType(type) ? ServiceKeys : type == "process.kill" ? KillKeys : IsFileType(type) ? FileKeys : NoKeys;
        var seen = new HashSet<string>(StringComparer.Ordinal);
        foreach (var property in p.EnumerateObject())
        {
            if (!seen.Add(property.Name))
            {
                return (false, "duplicate parameter");   // validar uma cópia e executar outra
            }

            if (Array.IndexOf(allowed, property.Name) < 0)
            {
                return (false, "parameter not allowed");
            }
        }

        if (IsServiceType(type))
        {
            if (!p.TryGetProperty("name", out var name) || name.ValueKind != JsonValueKind.String)
            {
                return (false, "name is required (string)");
            }

            return ServiceNamePattern().IsMatch(name.GetString()!) ? (true, null) : (false, "invalid service name");
        }

        if (IsFileType(type))
        {
            // Só o identificador: caminho e metadados vêm de GET /transfers/{id}, nunca dos parâmetros.
            if (!p.TryGetProperty("transfer_id", out var id) || id.ValueKind != JsonValueKind.String)
            {
                return (false, "transfer_id is required (string)");
            }

            return IsCanonicalUuid(id.GetString()) ? (true, null) : (false, "invalid transfer_id");
        }

        if (type == "process.kill")
        {
            if (!p.TryGetProperty("pid", out var pid) || pid.ValueKind != JsonValueKind.Number
                || !PidPattern().IsMatch(pid.GetRawText()) || !pid.TryGetInt32(out var pidValue) || pidValue < 5)
            {
                return (false, "invalid pid");   // 0-4 são Idle/System
            }

            if (!p.TryGetProperty("name", out var name) || name.ValueKind != JsonValueKind.String)
            {
                return (false, "name is required (string)");
            }

            return IsValidProcessName(name.GetString()!) ? (true, null) : (false, "invalid process name");
        }

        return (true, null);
    }

    /// <summary>1–128 caracteres (code points, como mb_strlen) e sem caracteres de controle (\x00-\x1F, \x7F).</summary>
    private static bool IsValidProcessName(string name)
    {
        var length = 0;
        foreach (var rune in name.EnumerateRunes())
        {
            if (rune.Value <= 0x1F || rune.Value == 0x7F)
            {
                return false;
            }

            length++;
        }

        return length is >= 1 and <= 128;
    }
}
