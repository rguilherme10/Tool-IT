using System.Globalization;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;

namespace AgentToolit.Core.Transfers;

/// <summary>
/// Validação de caminhos Windows para transferência de arquivos (ameaça T9: path traversal).
///
/// A parte SINTÁTICA (<see cref="ValidateDirectory"/>, <see cref="ValidateFileName"/>, <see cref="IsInside"/>,
/// <see cref="HasBlockedExtension"/>) é espelho de manager/app/Support/Transfers/PathGuard.php e passa pelo MESMO
/// vetor tests/contracts/path-guard-cases.json; o contrato está em docs/api/path-guard.md. Mudou uma regra: mude o
/// documento, o vetor e as duas implementações juntos.
///
/// O Agent acrescenta a parte de DISCO (<see cref="ValidateOnDisk"/>, <see cref="ValidateOpenHandle"/>):
/// GetFullPath, recusa de reparse points (junction/symlink) e conferência do caminho real do handle aberto.
///
/// Pontos de atenção (iguais ao PHP):
///  - Regras de caracteres avaliadas POR PONTO DE CÓDIGO (Rune): letras fora do BMP (ex.: U+1D400) são letras.
///  - Comprimentos em unidades UTF-16 (string.Length): total &lt;= 260, componente &lt;= 120.
///  - Comparação sem caixa dobra SÓ A-Z/a-z. Nunca OrdinalIgnoreCase/ToUpperInvariant: \u0131, \u017f e o Kelvin não
///    podem virar I, S, K.
/// </summary>
public static class PathGuard
{
    /// <summary>Extensões que nunca podem ser transferidas, mesmo se constarem da política.</summary>
    public static readonly IReadOnlySet<string> BlockedExtensions = new HashSet<string>(StringComparer.Ordinal)
    {
        "exe", "dll", "bat", "cmd", "ps1", "psm1", "vbs", "vbe", "js", "jse", "wsf", "msi", "msp", "scr", "com", "pif",
        "lnk", "hta", "jar", "cpl", "reg", "sys", "drv",
    };

    private const int MaxPath = 260;

    private const int MaxComponent = 120;

    /// <summary>Imitações de "/" e "\" que o NFKC não converte.</summary>
    private static readonly HashSet<int> ExtraHomoglyphs = [0x2044, 0x2215, 0x2216, 0x2236, 0x29F5, 0x29F8, 0x29F9, 0x2571, 0x2572, 0xA789];

    private static readonly HashSet<string> ReservedNames = BuildReservedNames();

    private static readonly Dictionary<string, string> Messages = new(StringComparer.Ordinal)
    {
        ["empty"] = "O caminho está vazio.",
        ["invalid_encoding"] = "O texto tem codificação inválida.",
        ["control_or_format"] = "Contém caracteres de controle ou invisíveis.",
        ["homoglyph"] = "Contém caracteres que imitam separadores, dois-pontos ou ponto.",
        ["device_path"] = @"Caminhos de dispositivo (\\?\ ou \\.\) não são permitidos.",
        ["unc"] = "Caminhos de rede (UNC) não são permitidos.",
        ["forward_slash"] = @"Use a contrabarra (\) como separador, não a barra (/).",
        ["relative"] = @"O caminho deve ser absoluto, como C:\Pasta.",
        ["too_long"] = "O caminho ou um de seus nomes é longo demais.",
        ["trailing_separator"] = "O caminho não pode terminar em contrabarra.",
        ["empty_component"] = "O caminho tem uma pasta vazia (contrabarras seguidas).",
        ["dotdot"] = "Os nomes \".\" e \"..\" não são permitidos.",
        ["trailing_dot_space"] = "Nomes não podem terminar em ponto ou espaço.",
        ["ads"] = "Dois-pontos (fluxos alternativos) não são permitidos.",
        ["short_name"] = "Nomes curtos 8.3 (com ~número) não são permitidos.",
        ["reserved"] = "Nome reservado do Windows (CON, NUL, COM1...).",
        ["char_not_allowed"] = "Só letras, números, espaço e . - _ ( ) são permitidos.",
        ["separator"] = "O nome não pode conter separadores de pasta.",
    };

    /// <summary>Mensagem curta em português para um código de motivo.</summary>
    public static string Message(string code) => Messages.TryGetValue(code, out var message) ? message : code;

    /// <summary>
    /// Motivo (mensagem) pelo qual o diretório/caminho absoluto é inválido, ou null.
    /// ATENÇÃO: é só sintaxe. Antes de tocar o disco chame também <see cref="ValidateOnDisk"/>.
    /// </summary>
    public static string? ValidateDirectory(string path) => DirectoryReason(path) is { } code ? Message(code) : null;

    /// <summary>
    /// Motivo (mensagem) pelo qual o nome de arquivo é inválido, ou null.
    /// ATENÇÃO: NÃO olha a extensão (".exe", "a.pdf.exe" são nomes válidos aqui): chame também <see cref="HasBlockedExtension"/>.
    /// </summary>
    public static string? ValidateFileName(string name) => FileNameReason(name) is { } code ? Message(code) : null;

    /// <summary>Código estável do motivo (o "why" do vetor) para um caminho absoluto, ou null se válido.</summary>
    public static string? DirectoryReason(string? path)
    {
        if (string.IsNullOrEmpty(path))
        {
            return "empty";
        }
        if (!IsValidUtf16(path))
        {
            return "invalid_encoding";
        }
        if (CharacterIssue(path) is { } issue)
        {
            return issue;
        }
        if (path.StartsWith(@"\\?\", StringComparison.Ordinal) || path.StartsWith(@"\\.\", StringComparison.Ordinal))
        {
            return "device_path";
        }
        if (path.StartsWith(@"\\", StringComparison.Ordinal))
        {
            return "unc";   // UNC é sempre recusado, mesmo que estivesse numa lista
        }
        if (path.Contains('/'))
        {
            return "forward_slash";
        }
        if (path.Length < 3 || !char.IsAsciiLetter(path[0]) || path[1] != ':' || path[2] != '\\')
        {
            return "relative";
        }
        if (path.Length > MaxPath)
        {
            return "too_long";
        }

        var rest = path[3..];
        if (rest.Length == 0)
        {
            return null;   // raiz da unidade, "X:\"
        }

        var parts = rest.Split('\\');
        var last = parts.Length - 1;
        for (var i = 0; i < parts.Length; i++)
        {
            if (parts[i].Length == 0)
            {
                return i == last && last > 0 ? "trailing_separator" : "empty_component";
            }
            if (ComponentReason(parts[i]) is { } code)
            {
                return code;
            }
        }

        return null;
    }

    /// <summary>Código estável do motivo para um nome de arquivo (um único componente), ou null se válido.</summary>
    public static string? FileNameReason(string? name)
    {
        if (string.IsNullOrEmpty(name))
        {
            return "empty";
        }
        if (!IsValidUtf16(name))
        {
            return "invalid_encoding";
        }
        if (CharacterIssue(name) is { } issue)
        {
            return issue;
        }
        if (name.Contains('/') || name.Contains('\\'))
        {
            return "separator";
        }

        return ComponentReason(name);
    }

    /// <summary>NFC (forma em que os nomes são gravados e comparados). Texto inválido: <see cref="ArgumentException"/>.</summary>
    public static string NormalizeName(string name)
    {
        var normalized = IsValidUtf16(name) ? TryNormalize(name, NormalizationForm.FormC) : null;

        return normalized ?? throw new ArgumentException(Message("invalid_encoding"), nameof(name));
    }

    /// <summary>
    /// <paramref name="path"/> (caminho absoluto, válido pelas regras de diretório) está dentro de algum de
    /// <paramref name="dirs"/>, ou é um deles? Sem caixa só em ASCII, com fronteira de separador ("Inbox" não contém
    /// "InboxX"). Caminho inválido ou diretório inválido da lista nunca casam.
    /// </summary>
    public static bool IsInside(string path, IEnumerable<string> dirs)
    {
        if (DirectoryReason(path) is not null)
        {
            return false;
        }
        var candidate = Fold(path);

        foreach (var dir in dirs)
        {
            if (DirectoryReason(dir) is not null)
            {
                continue;
            }
            var root = Fold(dir);
            var prefix = root.EndsWith('\\') ? root : root + "\\";
            if (string.Equals(candidate, root, StringComparison.Ordinal) || candidate.StartsWith(prefix, StringComparison.Ordinal))
            {
                return true;
            }
        }

        return false;
    }

    /// <summary>
    /// Junta diretório e nome de arquivo (NFC). <see cref="ArgumentException"/> se qualquer parte for inválida ou o
    /// resultado passar de 260: o resultado sempre está dentro do diretório informado.
    /// ATENÇÃO: NÃO checa extensão nem as raízes permitidas.
    /// </summary>
    public static string Join(string dir, string name)
    {
        if (DirectoryReason(dir) is { } dirCode)
        {
            throw new ArgumentException("Diretório inválido: " + Message(dirCode), nameof(dir));
        }
        if (FileNameReason(name) is { } nameCode)
        {
            throw new ArgumentException("Nome de arquivo inválido: " + Message(nameCode), nameof(name));
        }

        var normalizedDir = NormalizeName(dir);
        var joined = (normalizedDir.EndsWith('\\') ? normalizedDir : normalizedDir + "\\") + NormalizeName(name);

        return DirectoryReason(joined) is { } code ? throw new ArgumentException("Caminho inválido: " + Message(code)) : joined;
    }

    /// <summary>
    /// Alguma das extensões do nome (todas, não só a última) é executável/script? NFKC, remove Cc/Cf, separa por ".",
    /// descarta o nome-base, tira espaços ASCII das pontas de cada segmento e compara em minúsculas ASCII.
    /// ".exe" (nome só com extensão) é bloqueado. Em dúvida (texto inválido), bloqueia.
    /// </summary>
    public static bool HasBlockedExtension(string name)
    {
        if (!IsValidUtf16(name))
        {
            return true;
        }
        var normalized = TryNormalize(name, NormalizationForm.FormKC);
        if (normalized is null)
        {
            return true;
        }

        var visible = new StringBuilder(normalized.Length);
        foreach (var rune in normalized.EnumerateRunes())
        {
            var category = Rune.GetUnicodeCategory(rune);
            if (category is not (UnicodeCategory.Control or UnicodeCategory.Format))
            {
                visible.Append(rune.ToString());
            }
        }

        var segments = visible.ToString().Split('.');
        for (var i = 1; i < segments.Length; i++)
        {
            // Só o espaço ASCII (como o trim($s, ' ') do PHP) — não string.Trim(), que tira todo espaço Unicode.
            if (BlockedExtensions.Contains(AsciiLower(segments[i].Trim(' '))))
            {
                return true;
            }
        }

        return false;
    }

    /// <summary>
    /// Filtra as raízes locais configuradas: só as que passam <see cref="ValidateDirectory"/>. Cada inválida é
    /// informada a <paramref name="onInvalid"/> (raiz, motivo) para o chamador registrar no log.
    /// </summary>
    public static IReadOnlyList<string> UsableRoots(IEnumerable<string>? roots, Action<string, string>? onInvalid = null)
    {
        var usable = new List<string>();
        foreach (var root in roots ?? [])
        {
            if (DirectoryReason(root) is { } code)
            {
                onInvalid?.Invoke(root ?? "", Message(code));
                continue;
            }
            usable.Add(root);
        }

        return usable;
    }

    /// <summary>
    /// Conferência no DISCO antes de abrir/criar o arquivo. Motivo (mensagem) ou null se pode prosseguir:
    ///  1. sintaxe (<see cref="DirectoryReason"/>) e <see cref="Path.GetFullPath(string)"/> sem mudança (forma canônica);
    ///  2. dentro de alguma raiz válida de <paramref name="allowedRoots"/> (regra de <see cref="IsInside"/>);
    ///  3. unidade local (rede/desconhecida é recusada);
    ///  4. nenhum componente EXISTENTE, a partir da raiz da unidade, é reparse point (junction, symlink, montagem).
    /// Entre esta checagem e o uso o disco pode mudar: depois de abrir, confira com <see cref="ValidateOpenHandle"/>.
    /// </summary>
    public static string? ValidateOnDisk(string fullPath, IReadOnlyList<string> allowedRoots)
    {
        if (DirectoryReason(fullPath) is { } code)
        {
            return Message(code);
        }

        string resolved;
        try
        {
            resolved = Path.GetFullPath(fullPath);
        }
        catch (Exception ex) when (ex is ArgumentException or NotSupportedException or PathTooLongException)
        {
            return "O caminho não pôde ser resolvido.";
        }
        if (!string.Equals(resolved, fullPath, StringComparison.Ordinal))
        {
            return "O caminho não está na forma canônica.";
        }

        if (!IsInside(resolved, UsableRoots(allowedRoots)))
        {
            return "O caminho está fora das pastas permitidas neste computador.";
        }

        try
        {
            var drive = new DriveInfo(resolved[..3]);
            if (drive.DriveType is DriveType.Network or DriveType.NoRootDirectory or DriveType.Unknown)
            {
                return "Só unidades locais são permitidas.";
            }
        }
        catch (Exception ex) when (ex is ArgumentException or IOException or UnauthorizedAccessException)
        {
            return "Não foi possível identificar a unidade.";
        }

        var current = resolved[..3];
        foreach (var part in resolved[3..].Split('\\'))
        {
            current = current.EndsWith('\\') ? current + part : current + "\\" + part;

            FileAttributes attributes;
            try
            {
                // GetFileAttributesEx não segue o reparse point do último componente: vê o próprio link.
                attributes = File.GetAttributes(current);
            }
            catch (Exception ex) when (ex is FileNotFoundException or DirectoryNotFoundException)
            {
                break;   // daqui em diante nada existe ainda (será criado dentro de uma pasta já conferida)
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                return "Não foi possível verificar o caminho no disco.";
            }

            if ((attributes & FileAttributes.ReparsePoint) != 0)
            {
                return "O caminho passa por um link (junction/symlink), o que não é permitido.";
            }
        }

        return null;
    }

    /// <summary>
    /// Conferência DEPOIS de abrir: o caminho real do handle (GetFinalPathNameByHandle, links resolvidos) precisa estar
    /// dentro de uma raiz permitida. Fecha a janela entre <see cref="ValidateOnDisk"/> e a abertura (link trocado no meio).
    /// Motivo (mensagem) ou null.
    /// </summary>
    public static string? ValidateOpenHandle(SafeFileHandle handle, IReadOnlyList<string> roots)
    {
        if (handle is null || handle.IsInvalid || handle.IsClosed)
        {
            return "Arquivo não está aberto.";
        }

        var final = FinalPath(handle);
        if (final is null)
        {
            return "Não foi possível obter o caminho real do arquivo.";
        }
        if (final.StartsWith(@"\\?\UNC\", StringComparison.OrdinalIgnoreCase))
        {
            return Message("unc");
        }
        if (final.StartsWith(@"\\?\", StringComparison.Ordinal))
        {
            final = final[4..];
        }

        return IsInside(final, UsableRoots(roots)) ? null : "O arquivo aberto está fora das pastas permitidas neste computador.";
    }

    private static string? FinalPath(SafeFileHandle handle)
    {
        var buffer = new char[512];
        for (var attempt = 0; attempt < 3; attempt++)
        {
            var length = GetFinalPathNameByHandleW(handle, buffer, (uint)buffer.Length, FileNameNormalized | VolumeNameDos);
            if (length == 0)
            {
                return null;
            }
            if (length < buffer.Length)
            {
                return new string(buffer, 0, (int)length);
            }
            buffer = new char[length + 1];   // o retorno é o tamanho necessário (com o terminador)
        }

        return null;
    }

    private const uint FileNameNormalized = 0x0;

    private const uint VolumeNameDos = 0x0;

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true, ExactSpelling = true)]
    private static extern uint GetFinalPathNameByHandleW(SafeFileHandle hFile, [Out] char[] lpszFilePath, uint cchFilePath, uint dwFlags);

    private static string? ComponentReason(string component)
    {
        if (component is "." or "..")
        {
            return "dotdot";
        }
        var end = component[^1];
        if (end is '.' or ' ')
        {
            return "trailing_dot_space";
        }
        if (component.Length > MaxComponent)
        {
            return "too_long";
        }
        if (component.Contains(':'))
        {
            return "ads";
        }
        for (var i = 0; i + 1 < component.Length; i++)
        {
            if (component[i] == '~' && char.IsAsciiDigit(component[i + 1]))
            {
                return "short_name";
            }
        }
        if (IsReservedName(component))
        {
            return "reserved";
        }

        var composed = TryNormalize(component, NormalizationForm.FormC);
        if (composed is null)
        {
            return "char_not_allowed";
        }
        foreach (var rune in composed.EnumerateRunes())
        {
            if (!IsAllowed(rune))
            {
                return "char_not_allowed";
            }
        }

        // Caractere que o NFC troca sozinho por outro (Kelvin, Ohm, Angstrom, ideogramas de compatibilidade): no disco
        // é um nome diferente do que o NFC produz, então não pode ser aceito (senão "K" Kelvin casaria com "k").
        foreach (var rune in component.EnumerateRunes())
        {
            if (rune.Value < 0x80)
            {
                continue;
            }
            var single = rune.ToString();
            if (!string.Equals(TryNormalize(single, NormalizationForm.FormC), single, StringComparison.Ordinal))
            {
                return "char_not_allowed";
            }
        }

        return null;
    }

    /// <summary>Letras (\p{L}), números (\p{N}), espaço ASCII e ". - _ ( )".</summary>
    private static bool IsAllowed(Rune rune)
    {
        if (rune.Value is ' ' or '.' or '-' or '_' or '(' or ')')
        {
            return true;
        }

        return Rune.GetUnicodeCategory(rune) is UnicodeCategory.UppercaseLetter or UnicodeCategory.LowercaseLetter
            or UnicodeCategory.TitlecaseLetter or UnicodeCategory.ModifierLetter or UnicodeCategory.OtherLetter
            or UnicodeCategory.DecimalDigitNumber or UnicodeCategory.LetterNumber or UnicodeCategory.OtherNumber;
    }

    /// <summary>CON PRN AUX NUL COM1-9 LPT1-9, com ou sem extensão, ignorando espaços antes do ponto e largura total.</summary>
    private static bool IsReservedName(string component)
    {
        var normalized = TryNormalize(component, NormalizationForm.FormKC);
        if (normalized is null)
        {
            return true;
        }
        var dot = normalized.IndexOf('.');
        var baseName = (dot < 0 ? normalized : normalized[..dot]).TrimEnd(' ');

        return ReservedNames.Contains(AsciiUpper(baseName));
    }

    /// <summary>Controle/formato/separadores de linha e homógrafos de "/ \ : .".</summary>
    private static string? CharacterIssue(string text)
    {
        foreach (var rune in text.EnumerateRunes())
        {
            if (Rune.GetUnicodeCategory(rune) is UnicodeCategory.Control or UnicodeCategory.Format
                or UnicodeCategory.LineSeparator or UnicodeCategory.ParagraphSeparator)
            {
                return "control_or_format";
            }
        }

        foreach (var rune in text.EnumerateRunes())
        {
            if (rune.Value < 0x80)
            {
                continue;
            }
            if (ExtraHomoglyphs.Contains(rune.Value))
            {
                return "homoglyph";
            }
            var compat = TryNormalize(rune.ToString(), NormalizationForm.FormKC);
            if (compat is not null && compat.AsSpan().IndexOfAny(['/', '\\', ':', '.']) >= 0)
            {
                return "homoglyph";
            }
        }

        return null;
    }

    /// <summary>Sem substitutos (surrogates) soltos: o equivalente ao "UTF-8 válido" do PHP.</summary>
    private static bool IsValidUtf16(string text)
    {
        for (var i = 0; i < text.Length; i++)
        {
            var c = text[i];
            if (char.IsHighSurrogate(c))
            {
                if (i + 1 >= text.Length || !char.IsLowSurrogate(text[i + 1]))
                {
                    return false;
                }
                i++;
            }
            else if (char.IsLowSurrogate(c))
            {
                return false;
            }
        }

        return true;
    }

    private static string? TryNormalize(string text, NormalizationForm form)
    {
        try
        {
            return text.Normalize(form);
        }
        catch (ArgumentException)
        {
            return null;
        }
    }

    /// <summary>NFC + dobra de caixa SÓ ASCII (a-z -> A-Z): o resto dos caracteres é comparado exato.</summary>
    private static string Fold(string text) => AsciiUpper(NormalizeName(text));

    private static string AsciiUpper(string text) =>
        string.Create(text.Length, text, static (span, source) =>
        {
            for (var i = 0; i < source.Length; i++)
            {
                var c = source[i];
                span[i] = c is >= 'a' and <= 'z' ? (char)(c - 32) : c;
            }
        });

    private static string AsciiLower(string text) =>
        string.Create(text.Length, text, static (span, source) =>
        {
            for (var i = 0; i < source.Length; i++)
            {
                var c = source[i];
                span[i] = c is >= 'A' and <= 'Z' ? (char)(c + 32) : c;
            }
        });

    private static HashSet<string> BuildReservedNames()
    {
        var names = new HashSet<string>(StringComparer.Ordinal) { "CON", "PRN", "AUX", "NUL" };
        for (var i = 1; i <= 9; i++)
        {
            names.Add("COM" + i);
            names.Add("LPT" + i);
        }

        return names;
    }
}
