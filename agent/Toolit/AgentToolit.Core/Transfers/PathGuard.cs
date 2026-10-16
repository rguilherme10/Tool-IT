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
        [NormalizationUnavailable] = "A normalização Unicode não está disponível neste sistema: transferências de arquivo recusadas.",
        ["not_canonical"] = "O caminho não está na forma canônica.",
        ["outside_roots"] = "O caminho está fora das pastas permitidas neste computador.",
        ["not_local_drive"] = "Só unidades locais são permitidas.",
        ["disk_check_failed"] = "Não foi possível verificar o caminho no disco.",
        ["reparse_point"] = "O caminho passa por um link (junction/symlink), o que não é permitido.",
        ["handle_closed"] = "Arquivo não está aberto.",
        ["final_path_unavailable"] = "Não foi possível obter o caminho real do arquivo.",
        ["reparse_race"] = "O caminho mudou durante a abertura (link criado no meio): operação desfeita.",
        ["hard_link"] = "Arquivos com mais de um hard link não são permitidos.",
        ["exists"] = "O arquivo já existe.",
        ["not_found"] = "O arquivo ou a pasta não existe.",
        ["open_failed"] = "Não foi possível abrir o arquivo.",
    };

    /// <summary>Código de recusa quando NFC/NFKC não funcionam (ex.: globalização invariante).</summary>
    public const string NormalizationUnavailable = "normalization_unavailable";

    private static readonly Lazy<bool> NormalizationProbe = new(ProbeNormalization);

    private static readonly AsyncLocal<bool?> NormalizationOverride = new();

    /// <summary>Gancho de teste: chamado entre a checagem de disco e a abertura em <see cref="OpenChecked"/>.</summary>
    internal static readonly AsyncLocal<Action<string>?> BeforeOpenForTests = new();

    /// <summary>
    /// NFC/NFKC realmente funcionam neste processo? Com globalização invariante (ou sem ICU/NLS) Normalize vira no-op
    /// e TODAS as regras Unicode cairiam em silêncio (COM¹ deixaria de ser reservado, "a.ｅｘｅ" passaria). Falso:
    /// toda entrada do PathGuard recusa ("normalization_unavailable"); nunca roda em modo degradado.
    /// </summary>
    public static bool NormalizationAvailable => NormalizationOverride.Value ?? NormalizationProbe.Value;

    /// <summary>Só para testes (escopo do fluxo assíncrono atual): força o resultado de <see cref="NormalizationAvailable"/>.</summary>
    internal static void OverrideNormalizationForTests(bool? available) => NormalizationOverride.Value = available;

    private static bool ProbeNormalization()
    {
        try
        {
            return "e\u0301".Normalize(NormalizationForm.FormC) == "\u00e9"
                && "\uFF45\uFF58\uFF45".Normalize(NormalizationForm.FormKC) == "exe"
                && "COM\u00B9".Normalize(NormalizationForm.FormKC) == "COM1"
                && "\u212A".Normalize(NormalizationForm.FormC) == "K";
        }
        catch (Exception ex) when (ex is ArgumentException or PlatformNotSupportedException or NotSupportedException)
        {
            return false;
        }
    }

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
        if (!NormalizationAvailable)
        {
            return NormalizationUnavailable;
        }
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
        if (!NormalizationAvailable)
        {
            return NormalizationUnavailable;
        }
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

    /// <summary>
    /// NFC (forma em que os nomes são gravados e comparados). Texto inválido: <see cref="ArgumentException"/>; sem
    /// normalização disponível: <see cref="InvalidOperationException"/>.
    /// </summary>
    public static string NormalizeName(string name)
    {
        if (!NormalizationAvailable)
        {
            throw new InvalidOperationException(Message(NormalizationUnavailable));
        }
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
        if (!NormalizationAvailable || !IsValidUtf16(name))
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
    /// informada a <paramref name="onInvalid"/> (raiz, motivo) para o chamador registrar no log. Sem normalização
    /// Unicode disponível nenhuma raiz é utilizável (lista vazia).
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
    /// ATENÇÃO: entre esta checagem e a abertura o disco pode mudar (junction criada no meio, sem privilégio). Quem
    /// grava ou lê arquivo de transferência deve fazê-lo SÓ por <see cref="OpenChecked"/>, que repete esta checagem,
    /// abre sem truncar e confere o handle — nunca com File.Open/Create/FileMode.Create/Truncate/OpenOrCreate.
    /// </summary>
    public static string? ValidateOnDisk(string fullPath, IReadOnlyList<string> allowedRoots) =>
        DiskReason(fullPath, allowedRoots) is { } code ? Message(code) : null;

    /// <summary>
    /// Conferência DEPOIS de abrir: o caminho real do handle (GetFinalPathNameByHandle, links resolvidos) precisa estar
    /// dentro de uma raiz permitida. Motivo (mensagem) ou null.
    /// ATENÇÃO: sozinha não impede o estrago de uma abertura que cria/trunca fora da raiz; use <see cref="OpenChecked"/>
    /// para toda gravação/leitura (ele chama esta checagem e desfaz, pelo handle, o arquivo que acabou de criar).
    /// </summary>
    public static string? ValidateOpenHandle(SafeFileHandle handle, IReadOnlyList<string> roots) =>
        HandleReason(handle, roots) is { } code ? Message(code) : null;

    /// <summary>
    /// ÚNICA forma de abrir arquivo de transferência. Nunca cria/trunca por cima de nada:
    ///  - <see cref="FileOpenIntent.CreateNew"/>: arquivo novo (CREATE_NEW), escrita, sem compartilhamento;
    ///  - <see cref="FileOpenIntent.AppendExisting"/>: arquivo existente (OPEN_EXISTING), escrita no fim, sem truncar;
    ///  - <see cref="FileOpenIntent.ReadExisting"/>: arquivo existente, leitura, compartilhado só para leitura.
    /// Passos: <see cref="ValidateOnDisk"/> → abre → <see cref="ValidateOpenHandle"/> e recusa arquivo com mais de um
    /// hard link (Append/Read). Se o handle não confere e o arquivo foi criado POR ESTA chamada, ele é apagado PELO
    /// HANDLE (FileDispositionInfo) — nunca por caminho. Devolve null e <paramref name="error"/> (código; texto em
    /// <see cref="Message"/>): códigos de sintaxe/disco, "exists", "not_found", "open_failed", "reparse_race",
    /// "hard_link", "normalization_unavailable".
    /// </summary>
    public static FileStream? OpenChecked(string fullPath, FileOpenIntent intent, IReadOnlyList<string> roots, out string? error)
    {
        if (DiskReason(fullPath, roots) is { } diskCode)
        {
            error = diskCode;

            return null;
        }

        BeforeOpenForTests.Value?.Invoke(fullPath);

        var (access, share, disposition) = intent switch
        {
            FileOpenIntent.CreateNew => (GenericWrite | DeleteAccess, 0u, CreateNewDisposition),
            FileOpenIntent.AppendExisting => (GenericWrite, 0u, OpenExistingDisposition),
            FileOpenIntent.ReadExisting => (GenericRead, FileShareRead, OpenExistingDisposition),
            _ => throw new ArgumentOutOfRangeException(nameof(intent)),
        };

        var handle = CreateFileW(fullPath, access, share, IntPtr.Zero, disposition, FileAttributeNormal, IntPtr.Zero);
        if (handle.IsInvalid)
        {
            var win32 = Marshal.GetLastWin32Error();
            handle.Dispose();
            error = win32 switch
            {
                ErrorFileExists or ErrorAlreadyExists => "exists",
                ErrorFileNotFound or ErrorPathNotFound => "not_found",
                _ => "open_failed",
            };

            return null;
        }

        var created = intent == FileOpenIntent.CreateNew;
        string? problem = HandleReason(handle, roots) is { } handleCode
            ? (handleCode == "outside_roots" ? "reparse_race" : handleCode)
            : null;

        if (problem is null && !created && LinkCount(handle) is not 1)
        {
            problem = "hard_link";   // hard link plantado na raiz apontaria para um arquivo de fora (mesmo caminho final)
        }

        if (problem is not null)
        {
            if (created)
            {
                // Criamos o arquivo agora e ele está fora da raiz: apaga pelo próprio handle (não por caminho, que
                // pode já apontar para outro lugar).
                var info = new FileDispositionInfo { DeleteFile = true };
                SetFileInformationByHandle(handle, FileDispositionInfoClass, ref info, (uint)Marshal.SizeOf<FileDispositionInfo>());
            }
            handle.Dispose();
            error = problem;

            return null;
        }

        try
        {
            var stream = new FileStream(handle, intent == FileOpenIntent.ReadExisting ? FileAccess.Read : FileAccess.Write);
            if (intent == FileOpenIntent.AppendExisting)
            {
                stream.Seek(0, SeekOrigin.End);
            }
            error = null;

            return stream;
        }
        catch (Exception ex) when (ex is IOException or ArgumentException or UnauthorizedAccessException)
        {
            handle.Dispose();
            error = "open_failed";

            return null;
        }
    }

    /// <summary>Código do motivo de <see cref="ValidateOnDisk"/>, ou null.</summary>
    private static string? DiskReason(string fullPath, IReadOnlyList<string> allowedRoots)
    {
        if (DirectoryReason(fullPath) is { } code)
        {
            return code;
        }

        string resolved;
        try
        {
            resolved = Path.GetFullPath(fullPath);
        }
        catch (Exception ex) when (ex is ArgumentException or NotSupportedException or PathTooLongException)
        {
            return "not_canonical";
        }
        if (!string.Equals(resolved, fullPath, StringComparison.Ordinal))
        {
            return "not_canonical";
        }

        if (!IsInside(resolved, UsableRoots(allowedRoots)))
        {
            return "outside_roots";
        }

        try
        {
            var drive = new DriveInfo(resolved[..3]);
            if (drive.DriveType is DriveType.Network or DriveType.NoRootDirectory or DriveType.Unknown)
            {
                return "not_local_drive";
            }
        }
        catch (Exception ex) when (ex is ArgumentException or IOException or UnauthorizedAccessException)
        {
            return "not_local_drive";
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
                return "disk_check_failed";
            }

            if ((attributes & FileAttributes.ReparsePoint) != 0)
            {
                return "reparse_point";
            }
        }

        return null;
    }

    /// <summary>Código do motivo de <see cref="ValidateOpenHandle"/>, ou null.</summary>
    private static string? HandleReason(SafeFileHandle handle, IReadOnlyList<string> roots)
    {
        if (!NormalizationAvailable)
        {
            return NormalizationUnavailable;
        }
        if (handle is null || handle.IsInvalid || handle.IsClosed)
        {
            return "handle_closed";
        }

        var final = FinalPath(handle);
        if (final is null)
        {
            return "final_path_unavailable";
        }
        if (final.StartsWith(@"\\?\UNC\", StringComparison.OrdinalIgnoreCase))
        {
            return "unc";
        }
        if (final.StartsWith(@"\\?\", StringComparison.Ordinal))
        {
            final = final[4..];
        }

        return IsInside(final, UsableRoots(roots)) ? null : "outside_roots";
    }

    /// <summary>Número de hard links do arquivo aberto, ou null se não deu para ler.</summary>
    private static uint? LinkCount(SafeFileHandle handle) =>
        GetFileInformationByHandle(handle, out var info) ? info.NumberOfLinks : null;

    private const uint GenericRead = 0x80000000;
    private const uint GenericWrite = 0x40000000;
    private const uint DeleteAccess = 0x00010000;
    private const uint FileShareRead = 0x1;
    private const uint CreateNewDisposition = 1;
    private const uint OpenExistingDisposition = 3;
    private const uint FileAttributeNormal = 0x80;
    private const int FileDispositionInfoClass = 4;
    private const int ErrorFileNotFound = 2;
    private const int ErrorPathNotFound = 3;
    private const int ErrorFileExists = 80;
    private const int ErrorAlreadyExists = 183;

    [StructLayout(LayoutKind.Sequential)]
    private struct FileDispositionInfo
    {
        [MarshalAs(UnmanagedType.U1)]
        public bool DeleteFile;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ByHandleFileInformation
    {
        public uint FileAttributes;
        public uint CreationTimeLow, CreationTimeHigh;
        public uint LastAccessTimeLow, LastAccessTimeHigh;
        public uint LastWriteTimeLow, LastWriteTimeHigh;
        public uint VolumeSerialNumber;
        public uint FileSizeHigh, FileSizeLow;
        public uint NumberOfLinks;
        public uint FileIndexHigh, FileIndexLow;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true, ExactSpelling = true)]
    private static extern SafeFileHandle CreateFileW(
        string lpFileName, uint dwDesiredAccess, uint dwShareMode, IntPtr lpSecurityAttributes,
        uint dwCreationDisposition, uint dwFlagsAndAttributes, IntPtr hTemplateFile);

    [DllImport("kernel32.dll", SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetFileInformationByHandle(SafeFileHandle hFile, int fileInformationClass, ref FileDispositionInfo info, uint size);

    [DllImport("kernel32.dll", SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetFileInformationByHandle(SafeFileHandle hFile, out ByHandleFileInformation info);

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

/// <summary>Como <see cref="PathGuard.OpenChecked"/> abre o arquivo. Nenhuma opção cria por cima ou trunca.</summary>
public enum FileOpenIntent
{
    /// <summary>Arquivo novo (falha se já existe), escrita exclusiva.</summary>
    CreateNew,

    /// <summary>Arquivo existente, escrita exclusiva a partir do fim, sem truncar.</summary>
    AppendExisting,

    /// <summary>Arquivo existente, leitura (outros só podem ler).</summary>
    ReadExisting,
}
