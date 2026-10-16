using System.Runtime.InteropServices;
using System.Text;
using AgentToolit.Core.Transfers;
using AgentToolit.Tests.Support;

namespace AgentToolit.Tests;

/// <summary>
/// PathGuard: falha fechada sem normalização Unicode e abertura checada (<see cref="PathGuard.OpenChecked"/>) contra
/// corrida de junction e hard link. Só diretórios temporários criados pelo teste; links removidos sem recursão.
/// </summary>
public class PathGuardSafetyTests
{
    // ---------------------------------------------------------------- normalização

    [Fact]
    public void Test_environment_has_real_globalization_like_the_host()
    {
        Assert.False(AppContext.TryGetSwitch("System.Globalization.Invariant", out var invariant) && invariant,
            "o projeto de testes precisa de InvariantGlobalization=false, como o host");
        Assert.True(PathGuard.NormalizationAvailable);
    }

    [Fact]
    public void Unicode_rules_really_apply()
    {
        Assert.Equal("reserved", PathGuard.FileNameReason("COM\u00b9.txt"));
        Assert.Equal("reserved", PathGuard.FileNameReason("LPT\u00b3"));
        Assert.True(PathGuard.HasBlockedExtension("a.\uff45\uff58\uff45"));
        Assert.Equal("char_not_allowed", PathGuard.DirectoryReason("C:\\Dados\\\u212Aelvin"));
        var nfc = PathGuard.NormalizeName("e\u0301");
        Assert.Equal("\u00e9", nfc);
        Assert.Equal(1, nfc.Length);
    }

    [Fact]
    public void Without_normalization_every_entry_point_refuses()
    {
        using var dir = new TempDir();
        var (root, _) = PathGuardTests.Layout(dir);
        var file = Path.Combine(root, "a.txt");
        File.WriteAllText(file, "x");
        using var handle = File.OpenHandle(file);

        PathGuard.OverrideNormalizationForTests(false);
        try
        {
            Assert.False(PathGuard.NormalizationAvailable);
            Assert.Equal(PathGuard.NormalizationUnavailable, PathGuard.DirectoryReason("C:\\Dados"));
            Assert.Equal(PathGuard.NormalizationUnavailable, PathGuard.FileNameReason("a.txt"));
            Assert.NotNull(PathGuard.ValidateDirectory("C:\\Dados"));
            Assert.NotNull(PathGuard.ValidateFileName("a.txt"));
            Assert.Throws<InvalidOperationException>(() => PathGuard.NormalizeName("a"));
            Assert.False(PathGuard.IsInside("C:\\Dados\\a", ["C:\\Dados"]));
            Assert.Throws<ArgumentException>(() => PathGuard.Join("C:\\Dados", "a.txt"));
            Assert.True(PathGuard.HasBlockedExtension("a.txt"));
            Assert.Empty(PathGuard.UsableRoots(["C:\\Dados"]));
            Assert.NotNull(PathGuard.ValidateOnDisk(file, [root]));
            Assert.NotNull(PathGuard.ValidateOpenHandle(handle, [root]));

            Assert.Null(PathGuard.OpenChecked(file, FileOpenIntent.ReadExisting, [root], out var readError));
            Assert.Equal(PathGuard.NormalizationUnavailable, readError);
            Assert.Null(PathGuard.OpenChecked(Path.Combine(root, "novo.txt"), FileOpenIntent.CreateNew, [root], out var createError));
            Assert.Equal(PathGuard.NormalizationUnavailable, createError);
            Assert.False(File.Exists(Path.Combine(root, "novo.txt")));
        }
        finally
        {
            PathGuard.OverrideNormalizationForTests(null);
        }

        Assert.True(PathGuard.NormalizationAvailable);
        Assert.Null(PathGuard.ValidateOnDisk(file, [root]));
    }

    // ---------------------------------------------------------------- OpenChecked: flags da abertura

    [Fact]
    public void Raw_open_uses_anonymous_security_quality_of_service()
    {
        // Pipe nomeado plantado numa corrida não pode se passar pelo serviço (SECURITY_SQOS_PRESENT | SECURITY_ANONYMOUS).
        Assert.Equal(PathGuard.SecuritySqosPresent, PathGuard.OpenFlags & PathGuard.SecuritySqosPresent);
        Assert.Equal(0x00100000u, PathGuard.SecuritySqosPresent);
        Assert.Equal(0u, PathGuard.OpenFlags & PathGuard.SecurityDelegation);   // nível = ANONYMOUS (0), nunca identificação/impersonação/delegação
        Assert.Equal(0u, PathGuard.OpenFlags & 0x000C0000u);                    // nem CONTEXT_TRACKING nem EFFECTIVE_ONLY

        // E com essas flags a abertura normal continua funcionando nas três intenções.
        using var dir = new TempDir();
        var (root, _) = PathGuardTests.Layout(dir);
        var file = Path.Combine(root, "sqos.txt");
        using (var s = PathGuard.OpenChecked(file, FileOpenIntent.CreateNew, [root], out var e1))
        {
            Assert.Null(e1);
            s!.Write("x"u8);
        }
        using (var s = PathGuard.OpenChecked(file, FileOpenIntent.AppendExisting, [root], out var e2))
        {
            Assert.Null(e2);
            Assert.NotNull(s);
        }
        using (var s = PathGuard.OpenChecked(file, FileOpenIntent.ReadExisting, [root], out var e3))
        {
            Assert.Null(e3);
            Assert.Equal('x', s!.ReadByte());
        }
    }

    // ---------------------------------------------------------------- OpenChecked: caminho normal

    [Fact]
    public void Create_append_and_read_inside_the_root_work()
    {
        using var dir = new TempDir();
        var (root, _) = PathGuardTests.Layout(dir);
        var file = Path.Combine(root, "a.txt");

        using (var created = PathGuard.OpenChecked(file, FileOpenIntent.CreateNew, [root], out var error))
        {
            Assert.Null(error);
            Assert.NotNull(created);
            created.Write("abc"u8);
        }

        using (var append = PathGuard.OpenChecked(file, FileOpenIntent.AppendExisting, [root], out var error))
        {
            Assert.Null(error);
            Assert.NotNull(append);
            Assert.Equal(3, append.Position);   // no fim, sem truncar
            append.Write("def"u8);
        }

        using (var read = PathGuard.OpenChecked(file, FileOpenIntent.ReadExisting, [root], out var error))
        {
            Assert.Null(error);
            Assert.NotNull(read);
            Assert.False(read.CanWrite);
            using var reader = new StreamReader(read, Encoding.UTF8);
            Assert.Equal("abcdef", reader.ReadToEnd());
        }
    }

    [Fact]
    public void Create_new_never_truncates_an_existing_file()
    {
        using var dir = new TempDir();
        var (root, _) = PathGuardTests.Layout(dir);
        var file = Path.Combine(root, "a.txt");
        File.WriteAllText(file, "conteúdo original");

        Assert.Null(PathGuard.OpenChecked(file, FileOpenIntent.CreateNew, [root], out var error));
        Assert.Equal("exists", error);
        Assert.Equal("conteúdo original", File.ReadAllText(file));
    }

    [Fact]
    public void Existing_intents_need_an_existing_file_and_outside_paths_are_refused()
    {
        using var dir = new TempDir();
        var (root, outside) = PathGuardTests.Layout(dir);

        Assert.Null(PathGuard.OpenChecked(Path.Combine(root, "nada.txt"), FileOpenIntent.AppendExisting, [root], out var e1));
        Assert.Equal("not_found", e1);
        Assert.False(File.Exists(Path.Combine(root, "nada.txt")));

        Assert.Null(PathGuard.OpenChecked(Path.Combine(outside, "x.txt"), FileOpenIntent.CreateNew, [root], out var e2));
        Assert.Equal("outside_roots", e2);
        Assert.False(File.Exists(Path.Combine(outside, "x.txt")));

        Assert.Null(PathGuard.OpenChecked(root + "\\..\\Secret\\x.txt", FileOpenIntent.CreateNew, [root], out var e3));
        Assert.Equal("dotdot", e3);
    }

    // ---------------------------------------------------------------- OpenChecked: links

    [Fact]
    public void A_junction_already_on_the_path_is_refused_and_nothing_is_created_outside()
    {
        using var dir = new TempDir();
        var (root, outside) = PathGuardTests.Layout(dir);
        File.WriteAllText(Path.Combine(outside, "a.txt"), "segredo");
        var sub = Path.Combine(root, "sub");
        PathGuardTests.CreateDirectoryLink(sub, outside);

        try
        {
            Assert.Null(PathGuard.OpenChecked(Path.Combine(sub, "x.txt"), FileOpenIntent.CreateNew, [root], out var e1));
            Assert.Equal("reparse_point", e1);
            Assert.Null(PathGuard.OpenChecked(Path.Combine(sub, "a.txt"), FileOpenIntent.AppendExisting, [root], out var e2));
            Assert.Equal("reparse_point", e2);
            // O próprio componente final é o link.
            Assert.Null(PathGuard.OpenChecked(sub, FileOpenIntent.ReadExisting, [root], out var e3));
            Assert.Equal("reparse_point", e3);
        }
        finally
        {
            Directory.Delete(sub);   // só o link, sem recursão
        }

        Assert.False(File.Exists(Path.Combine(outside, "x.txt")));
        Assert.Equal("segredo", File.ReadAllText(Path.Combine(outside, "a.txt")));
    }

    [Fact]
    public void Junction_created_after_the_disk_check_undoes_the_new_file_by_handle()
    {
        using var dir = new TempDir();
        var (root, outside) = PathGuardTests.Layout(dir);
        var sub = Path.Combine(root, "sub");
        Directory.CreateDirectory(sub);
        var target = Path.Combine(sub, "x.txt");
        Assert.Null(PathGuard.ValidateOnDisk(target, [root]));   // a checagem de disco passa...

        var swapped = false;
        PathGuard.BeforeOpenForTests.Value = _ =>
        {
            // ...e o "atacante" troca a pasta por uma junction para fora antes da abertura.
            Directory.Delete(sub);
            PathGuardTests.CreateDirectoryLink(sub, outside);
            swapped = true;
        };

        try
        {
            Assert.Null(PathGuard.OpenChecked(target, FileOpenIntent.CreateNew, [root], out var error));
            Assert.True(swapped);
            Assert.Equal("reparse_race", error);
        }
        finally
        {
            PathGuard.BeforeOpenForTests.Value = null;
            if (swapped)
            {
                Directory.Delete(sub);
            }
        }

        Assert.Empty(Directory.EnumerateFileSystemEntries(outside));   // nada ficou fora da raiz
    }

    [Theory]
    [InlineData(FileOpenIntent.AppendExisting)]
    [InlineData(FileOpenIntent.ReadExisting)]
    public void Junction_swapped_in_before_opening_an_existing_file_is_refused_and_the_target_is_untouched(FileOpenIntent intent)
    {
        using var dir = new TempDir();
        var (root, outside) = PathGuardTests.Layout(dir);
        var sub = Path.Combine(root, "sub");
        Directory.CreateDirectory(sub);
        File.WriteAllText(Path.Combine(sub, "a.txt"), "dentro");
        File.WriteAllText(Path.Combine(outside, "a.txt"), "segredo");

        var swapped = false;
        PathGuard.BeforeOpenForTests.Value = _ =>
        {
            Directory.Move(sub, Path.Combine(root, "sub-antiga"));
            PathGuardTests.CreateDirectoryLink(sub, outside);
            swapped = true;
        };

        try
        {
            Assert.Null(PathGuard.OpenChecked(Path.Combine(sub, "a.txt"), intent, [root], out var error));
            Assert.True(swapped);
            Assert.Equal("reparse_race", error);
        }
        finally
        {
            PathGuard.BeforeOpenForTests.Value = null;
            if (swapped)
            {
                Directory.Delete(sub);
            }
        }

        Assert.Equal("segredo", File.ReadAllText(Path.Combine(outside, "a.txt")));   // não apagado nem alterado
    }

    [Theory]
    [InlineData(FileOpenIntent.AppendExisting)]
    [InlineData(FileOpenIntent.ReadExisting)]
    public void A_hard_link_inside_the_root_to_an_outside_file_is_refused(FileOpenIntent intent)
    {
        using var dir = new TempDir();
        var (root, outside) = PathGuardTests.Layout(dir);
        var secret = Path.Combine(outside, "segredo.txt");
        File.WriteAllText(secret, "segredo");
        var link = Path.Combine(root, "h.txt");

        Assert.True(CreateHardLinkW(link, secret, IntPtr.Zero),
            "Ambiente não permitiu criar hard link (CreateHardLinkW); o teste NÃO foi executado. Erro Win32 " + Marshal.GetLastWin32Error());

        Assert.Null(PathGuard.OpenChecked(link, intent, [root], out var error));
        Assert.Equal("hard_link", error);
        Assert.Equal("segredo", File.ReadAllText(secret));
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateHardLinkW(string lpFileName, string lpExistingFileName, IntPtr lpSecurityAttributes);
}
