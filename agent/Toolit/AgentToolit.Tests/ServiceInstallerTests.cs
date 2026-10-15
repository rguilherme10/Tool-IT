using AgentToolit.Core.Install;
using AgentToolit.Tests.Support;

namespace AgentToolit.Tests;

public class ServiceInstallerTests
{
    private sealed class FakeRunner(Func<string, string[], ProcessResult> respond) : IProcessRunner
    {
        public List<string[]> Calls { get; } = [];

        public ProcessResult Run(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout)
        {
            Assert.Equal("sc.exe", fileName);
            Calls.Add([.. arguments]);

            return respond(arguments[0], [.. arguments]);
        }

        public IEnumerable<string> Verbs => Calls.Select(c => c[0]);
    }

    private static ProcessResult Ok(string output = "") => new(0, output);

    private static string ExistingExe(TempDir dir)
    {
        var exe = Path.Combine(dir.Path, "Program Files", "Toolit", "AgentToolit.exe"); // com espaço, como em %ProgramFiles%
        Directory.CreateDirectory(Path.GetDirectoryName(exe)!);
        File.WriteAllText(exe, "x");

        return exe;
    }

    [Fact]
    public void Fresh_install_creates_configures_recovery_and_starts_the_service()
    {
        using var dir = new TempDir();
        var exe = ExistingExe(dir);
        var running = false;
        var runner = new FakeRunner((verb, _) =>
        {
            switch (verb)
            {
                case "query": return running ? Ok("SERVICE_NAME: ToolitAgent\n  ESTADO : 4  RUNNING") : new ProcessResult(1060, "");
                case "start": running = true; return Ok();
                default: return Ok();
            }
        });

        new ServiceInstaller(runner, sleep: _ => { }).Install(exe);

        Assert.Equal(["query", "create", "description", "failure", "failureflag", "start", "query"], runner.Verbs);
        var create = runner.Calls[1];
        Assert.Equal(["create", "ToolitAgent", "binPath=", $"\"{exe}\"", "start=", "delayed-auto", "obj=", "LocalSystem", "DisplayName=", "Toolit Agent"], create);
        Assert.Equal(["failure", "ToolitAgent", "reset=", "86400", "actions=", "restart/5000/restart/30000/restart/60000"], runner.Calls[3]);
        Assert.Equal(["failureflag", "ToolitAgent", "1"], runner.Calls[4]);
    }

    [Fact]
    public void Reinstall_updates_the_existing_service_instead_of_failing()
    {
        using var dir = new TempDir();
        var exe = ExistingExe(dir);
        var runner = new FakeRunner((verb, _) => verb == "query" ? Ok("STATE : 4  RUNNING") : verb == "start" ? new ProcessResult(1056, "já em execução") : Ok());

        new ServiceInstaller(runner, sleep: _ => { }).Install(exe);

        Assert.Equal("config", runner.Calls[1][0]);   // não 'create'
        Assert.Contains("start", runner.Verbs);       // 1056 (já em execução) é aceito
    }

    [Fact]
    public void Failure_in_any_step_surfaces_the_sc_exit_code()
    {
        using var dir = new TempDir();
        var exe = ExistingExe(dir);
        var runner = new FakeRunner((verb, _) => verb == "query" ? new ProcessResult(1060, "") : verb == "create" ? new ProcessResult(5, "Acesso negado.") : Ok());

        var ex = Assert.Throws<ServiceInstallException>(() => new ServiceInstaller(runner, sleep: _ => { }).Install(exe));

        Assert.Contains("create", ex.Message);
        Assert.Contains("5", ex.Message);
        Assert.Contains("Acesso negado", ex.Message);
    }

    [Fact]
    public void Install_fails_if_the_service_never_reaches_running()
    {
        using var dir = new TempDir();
        var exe = ExistingExe(dir);
        var runner = new FakeRunner((verb, _) => verb == "query" ? new ProcessResult(0, "STATE : 2  START_PENDING") : Ok());

        var ex = Assert.Throws<ServiceInstallException>(() => new ServiceInstaller(runner, sleep: _ => { }).Install(exe, TimeSpan.FromSeconds(2)));

        Assert.Contains("RUNNING", ex.Message);
    }

    [Theory]
    [InlineData("relativo\\AgentToolit.exe")]
    [InlineData("C:\\nao\\existe\\AgentToolit.exe")]
    [InlineData("C:\\x\"y.exe")]
    [InlineData("")]
    public void Executable_path_is_validated_before_touching_the_service_manager(string path)
    {
        var runner = new FakeRunner((_, _) => Ok());

        Assert.Throws<ServiceInstallException>(() => new ServiceInstaller(runner).Install(path));
        Assert.Empty(runner.Calls);
    }

    [Fact]
    public void Non_exe_and_control_characters_are_rejected()
    {
        using var dir = new TempDir();
        var txt = Path.Combine(dir.Path, "a.txt");
        File.WriteAllText(txt, "x");
        var withControl = Path.Combine(dir.Path, "b\n.exe");

        Assert.Throws<ServiceInstallException>(() => ServiceInstaller.ValidateExePath(txt));
        Assert.Throws<ServiceInstallException>(() => ServiceInstaller.ValidateExePath(withControl));
    }

    [Fact]
    public void Uninstall_stops_waits_and_deletes()
    {
        var running = true;
        var runner = new FakeRunner((verb, _) =>
        {
            switch (verb)
            {
                case "query": return Ok(running ? "STATE : 4  RUNNING" : "STATE : 1  STOPPED");
                case "stop": running = false; return Ok();
                default: return Ok();
            }
        });

        new ServiceInstaller(runner, sleep: _ => { }).Uninstall();

        Assert.Equal(["query", "stop", "query", "delete"], runner.Verbs);
    }

    [Fact]
    public void Uninstall_is_a_noop_when_the_service_does_not_exist_and_tolerates_already_stopped()
    {
        var none = new FakeRunner((_, _) => new ProcessResult(1060, ""));
        new ServiceInstaller(none).Uninstall();
        Assert.Equal(["query"], none.Verbs);

        var stopped = new FakeRunner((verb, _) => verb == "stop" ? new ProcessResult(1062, "não iniciado") : Ok("STATE : 1  STOPPED"));
        new ServiceInstaller(stopped, sleep: _ => { }).Uninstall();
        Assert.Contains("delete", stopped.Verbs);
    }

    [Fact]
    public void Real_sc_exe_reports_a_missing_service_without_needing_administrator()
    {
        var installer = new ServiceInstaller(new SystemProcessRunner(), "ToolitAgent_TesteInexistente_" + Guid.NewGuid().ToString("N")[..8]);

        Assert.False(installer.Exists());
        Assert.False(installer.IsRunning());
        installer.Uninstall(); // nada a fazer, não lança
    }
}
