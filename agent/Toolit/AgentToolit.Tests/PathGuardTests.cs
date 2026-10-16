using System.Diagnostics;
using System.Text.Json;
using AgentToolit.Core.Transfers;
using AgentToolit.Tests.Support;
using Xunit.Abstractions;

namespace AgentToolit.Tests;

/// <summary>
/// PathGuard do Agent: o vetor compartilhado com o Manager (tests/contracts/path-guard-cases.json, mesmos ok/blocked do
/// PHP) e as conferências de disco (GetFullPath, reparse points, handle). Só diretórios temporários criados pelo teste.
/// </summary>
public class PathGuardTests(ITestOutputHelper output)
{
    private static JsonElement Vector()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null && !File.Exists(Path.Combine(dir.FullName, "tests", "contracts", "path-guard-cases.json")))
        {
            dir = dir.Parent;
        }

        Assert.NotNull(dir);

        return JsonDocument.Parse(File.ReadAllText(Path.Combine(dir!.FullName, "tests", "contracts", "path-guard-cases.json"))).RootElement;
    }

    private static IEnumerable<JsonElement> Section(string name) => Vector().GetProperty(name).EnumerateArray();

    /// <summary>Texto legível do caso para a mensagem de falha (com escapes \uXXXX).</summary>
    private static string Show(string text) => JsonSerializer.Serialize(text);

    [Fact]
    public void Shared_vector_directories_match_the_manager()
    {
        var failures = new List<string>();
        var count = 0;
        foreach (var c in Section("directories"))
        {
            count++;
            var path = c.GetProperty("path").GetString()!;
            var reason = PathGuard.ValidateDirectory(path);
            if (c.GetProperty("ok").GetBoolean() != (reason is null))
            {
                failures.Add($"{Show(path)} esperado ok={c.GetProperty("ok").GetBoolean()} why={c.GetProperty("why").GetString()} obtido={PathGuard.DirectoryReason(path) ?? "ok"}");
            }
        }

        Assert.True(count > 50);
        Assert.True(failures.Count == 0, string.Join("\n", failures));
    }

    [Fact]
    public void Shared_vector_files_match_the_manager()
    {
        var failures = new List<string>();
        var count = 0;
        foreach (var c in Section("files"))
        {
            count++;
            var name = c.GetProperty("name").GetString()!;
            var reason = PathGuard.ValidateFileName(name);
            if (c.GetProperty("ok").GetBoolean() != (reason is null))
            {
                failures.Add($"{Show(name)} esperado ok={c.GetProperty("ok").GetBoolean()} why={c.GetProperty("why").GetString()} obtido={PathGuard.FileNameReason(name) ?? "ok"}");
            }
        }

        Assert.True(count > 50);
        Assert.True(failures.Count == 0, string.Join("\n", failures));
    }

    [Fact]
    public void Shared_vector_inside_matches_the_manager()
    {
        var failures = new List<string>();
        var count = 0;
        foreach (var c in Section("inside"))
        {
            count++;
            var path = c.GetProperty("path").GetString()!;
            var dirs = c.GetProperty("dirs").EnumerateArray().Select(d => d.GetString()!).ToList();
            if (c.GetProperty("ok").GetBoolean() != PathGuard.IsInside(path, dirs))
            {
                failures.Add($"{Show(path)} em [{string.Join(", ", dirs.Select(Show))}] esperado {c.GetProperty("ok").GetBoolean()}");
            }
        }

        Assert.True(count > 30);
        Assert.True(failures.Count == 0, string.Join("\n", failures));
    }

    [Fact]
    public void Shared_vector_blocked_extensions_match_the_manager()
    {
        var failures = new List<string>();
        var count = 0;
        foreach (var c in Section("blocked_extensions"))
        {
            count++;
            var name = c.GetProperty("name").GetString()!;
            if (c.GetProperty("blocked").GetBoolean() != PathGuard.HasBlockedExtension(name))
            {
                failures.Add($"{Show(name)} esperado blocked={c.GetProperty("blocked").GetBoolean()}");
            }
        }

        Assert.True(count > 40);
        Assert.True(failures.Count == 0, string.Join("\n", failures));
    }

    [Fact]
    public void Shared_vector_has_short_supplementary_plane_letter_cases()
    {
        // Letra fora do BMP (U+1D400) num nome curto: as regras são por ponto de código, não por unidade UTF-16.
        static bool Short(string text) => text.Length < 30 && text.EnumerateRunes().Any(r => r.Value == 0x1D400);

        Assert.Contains(Section("directories"), c => c.GetProperty("ok").GetBoolean() && Short(c.GetProperty("path").GetString()!));
        Assert.Contains(Section("files"), c => c.GetProperty("ok").GetBoolean() && Short(c.GetProperty("name").GetString()!));
    }

    [Fact]
    public void Lone_surrogates_are_invalid_and_count_as_blocked_extension()
    {
        Assert.Equal("invalid_encoding", PathGuard.DirectoryReason("C:\\a\\x\ud835y"));
        Assert.Equal("invalid_encoding", PathGuard.FileNameReason("a\udc00.txt"));
        Assert.True(PathGuard.HasBlockedExtension("a\ud835.txt"));
        Assert.Throws<ArgumentException>(() => PathGuard.NormalizeName("a\udc00"));
    }

    [Fact]
    public void Extension_segments_are_trimmed_of_ascii_space_only()
    {
        Assert.True(PathGuard.HasBlockedExtension("a. exe "));
        // NBSP (U+00A0) vira espaço ASCII no NFKC e então é aparado como o PHP faria; U+3000 também (NFKC -> espaço).
        Assert.True(PathGuard.HasBlockedExtension("a.exe\u00a0"));
        // Resultados conferidos no PHP (trim($s, ' ')). U+1680 (Ogham) e U+2028 são "espaço" para string.Trim(), mas
        // não para a regra: a extensão fica "exe" + U+1680, livre. Não há brecha: esses nomes são recusados pelas
        // regras de nome antes de qualquer gravação.
        Assert.True(PathGuard.HasBlockedExtension("a.exe\u3000"));
        Assert.False(PathGuard.HasBlockedExtension("a.exe\u1680"));
        Assert.False(PathGuard.HasBlockedExtension("a.exe\u2028"));
        Assert.Equal("char_not_allowed", PathGuard.FileNameReason("a.exe\u1680"));
        Assert.Equal("control_or_format", PathGuard.FileNameReason("a.exe\u2028"));
        // Espaço de largura zero é Cf: removido antes de separar.
        Assert.True(PathGuard.HasBlockedExtension("a.e\u200bxe"));
    }

    [Fact]
    public void Join_normalizes_and_keeps_the_result_inside_the_directory()
    {
        Assert.Equal("C:\\Dados\\relat\u00f3rio.pdf", PathGuard.Join("C:\\Dados", "relato\u0301rio.pdf"));
        Assert.Equal("C:\\a.txt", PathGuard.Join("C:\\", "a.txt"));
        Assert.Throws<ArgumentException>(() => PathGuard.Join("C:\\Dados", "..\\x"));
        Assert.Throws<ArgumentException>(() => PathGuard.Join("\\\\srv\\share", "a.txt"));
    }

    [Fact]
    public void Usable_roots_skip_and_report_invalid_entries()
    {
        var rejected = new List<string>();
        var roots = PathGuard.UsableRoots(["C:\\Dados\\Inbox", "\\\\srv\\share", "relativo", "C:\\Dados\\Inbox\\"], (root, _) => rejected.Add(root));

        Assert.Equal(new[] { "C:\\Dados\\Inbox" }, roots);
        Assert.Equal(new[] { "\\\\srv\\share", "relativo", "C:\\Dados\\Inbox\\" }, rejected);
    }

    // ---------------------------------------------------------------- disco

    /// <summary>Raiz permitida e uma pasta "de fora", ambas dentro de um diretório temporário do teste.</summary>
    internal static (string Root, string Outside) Layout(TempDir dir)
    {
        var baseDir = Path.GetFullPath(dir.Path);
        Assert.Null(PathGuard.ValidateDirectory(baseDir));   // o diretório temporário precisa ser um caminho aceitável

        var root = Path.Combine(baseDir, "Inbox");
        var outside = Path.Combine(baseDir, "Secret");
        Directory.CreateDirectory(root);
        Directory.CreateDirectory(outside);

        return (root, outside);
    }

    [Fact]
    public void On_disk_a_normal_file_inside_the_root_is_accepted()
    {
        using var dir = new TempDir();
        var (root, _) = Layout(dir);
        var file = Path.Combine(root, "a.txt");
        File.WriteAllText(file, "x");

        Assert.Null(PathGuard.ValidateOnDisk(file, [root]));
        Assert.Null(PathGuard.ValidateOnDisk(Path.Combine(root, "novo.txt"), [root]));          // ainda não existe
        Assert.Null(PathGuard.ValidateOnDisk(Path.Combine(root, "sub", "novo.txt"), [root]));   // pasta ainda não existe
        Assert.Null(PathGuard.ValidateOnDisk(root, [root]));
    }

    [Fact]
    public void On_disk_dotdot_and_paths_outside_the_root_are_refused()
    {
        using var dir = new TempDir();
        var (root, outside) = Layout(dir);
        File.WriteAllText(Path.Combine(outside, "a.txt"), "x");

        Assert.NotNull(PathGuard.ValidateOnDisk(root + "\\..\\Secret\\a.txt", [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk(root + "\\.\\a.txt", [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(outside, "a.txt"), [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(root, "a.txt"), []));                 // nenhuma raiz
        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(root, "a.txt"), ["\\\\srv\\share"]));  // raiz inválida ignorada
        Assert.NotNull(PathGuard.ValidateOnDisk(root.Replace('\\', '/') + "/a.txt", [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk("\\\\?\\" + Path.Combine(root, "a.txt"), [root]));
    }

    [Fact]
    public void On_disk_a_prefix_lookalike_is_refused()
    {
        using var dir = new TempDir();
        var (root, _) = Layout(dir);
        var lookalike = root + "X";
        Directory.CreateDirectory(lookalike);
        File.WriteAllText(Path.Combine(lookalike, "a.txt"), "x");

        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(lookalike, "a.txt"), [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk(lookalike, [root]));
    }

    [Fact]
    public void On_disk_case_fold_is_ascii_only()
    {
        using var dir = new TempDir();
        var (root, _) = Layout(dir);
        var parent = Path.GetDirectoryName(root)!;

        // Mesma pasta em outra caixa ASCII: aceita (o NTFS não diferencia).
        Assert.Null(PathGuard.ValidateOnDisk(Path.Combine(parent, "INBOX", "a.txt"), [root]));
        // "\u0131nbox" (i sem ponto, U+0131) NÃO é "Inbox": recusado, mesmo que o sistema de arquivos achasse parecido.
        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(parent, "\u0131nbox", "a.txt"), [root]));
        Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(root, "a.txt"), [Path.Combine(parent, "\u0131nbox")]));
    }

    [Fact]
    public void On_disk_a_link_inside_the_root_pointing_outside_is_refused()
    {
        using var dir = new TempDir();
        var (root, outside) = Layout(dir);
        File.WriteAllText(Path.Combine(outside, "a.txt"), "segredo");
        var link = Path.Combine(root, "atalho");

        var kind = CreateDirectoryLink(link, outside);
        output.WriteLine($"link criado como {kind}");

        try
        {
            Assert.True((File.GetAttributes(link) & FileAttributes.ReparsePoint) != 0, $"o {kind} não foi criado como reparse point");
            Assert.NotNull(PathGuard.ValidateOnDisk(link, [root]));
            Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(link, "a.txt"), [root]));
            Assert.NotNull(PathGuard.ValidateOnDisk(Path.Combine(link, "novo.txt"), [root]));

            // Mesmo depois de aberto pelo link, o caminho real do handle está fora da raiz.
            using var handle = File.OpenHandle(Path.Combine(link, "a.txt"));
            Assert.NotNull(PathGuard.ValidateOpenHandle(handle, [root]));
            Assert.Null(PathGuard.ValidateOpenHandle(handle, [outside]));
        }
        finally
        {
            // Remove só o link (não recursivo: nunca apaga o conteúdo do alvo).
            Directory.Delete(link);
        }

        Assert.True(File.Exists(Path.Combine(outside, "a.txt")));
    }

    [Fact]
    public void Open_handle_inside_the_root_is_accepted_and_outside_is_refused()
    {
        using var dir = new TempDir();
        var (root, outside) = Layout(dir);
        var inside = Path.Combine(root, "a.txt");
        var other = Path.Combine(outside, "b.txt");
        File.WriteAllText(inside, "x");
        File.WriteAllText(other, "y");

        using (var h = File.OpenHandle(inside))
        {
            Assert.Null(PathGuard.ValidateOpenHandle(h, [root]));
            Assert.NotNull(PathGuard.ValidateOpenHandle(h, [outside]));
            Assert.NotNull(PathGuard.ValidateOpenHandle(h, [root + "X"]));
            Assert.NotNull(PathGuard.ValidateOpenHandle(h, []));
        }

        using (var h = File.OpenHandle(other))
        {
            Assert.NotNull(PathGuard.ValidateOpenHandle(h, [root]));
        }

        var closed = File.OpenHandle(inside);
        closed.Dispose();
        Assert.NotNull(PathGuard.ValidateOpenHandle(closed, [root]));
    }

    /// <summary>
    /// Cria um link de diretório descartável: symlink (exige privilégio/modo desenvolvedor) ou, sem ele, junction via
    /// "mklink /J" (sem privilégio). Os caminhos vêm do próprio teste e vão em ArgumentList (sem montar string de shell).
    /// Se nenhum der certo o teste FALHA com mensagem explícita — nunca passa sem ter testado o link.
    /// </summary>
    internal static string CreateDirectoryLink(string link, string target)
    {
        try
        {
            Directory.CreateSymbolicLink(link, target);

            return "symlink";
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            // sem SeCreateSymbolicLinkPrivilege: tenta junction
        }

        var info = new ProcessStartInfo("cmd.exe")
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var arg in new[] { "/d", "/c", "mklink", "/J", link, target })
        {
            info.ArgumentList.Add(arg);
        }

        using var process = Process.Start(info)!;
        var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
        process.WaitForExit(30_000);

        Assert.True(process.ExitCode == 0 && Directory.Exists(link),
            "Ambiente não permitiu criar symlink nem junction (mklink /J); o teste de reparse point NÃO foi executado: " + output);

        return "junction";
    }
}
