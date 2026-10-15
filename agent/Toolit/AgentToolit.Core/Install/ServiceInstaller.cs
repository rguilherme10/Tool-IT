using System.Diagnostics;
using System.Text.RegularExpressions;

namespace AgentToolit.Core.Install;

public sealed record ProcessResult(int ExitCode, string Output);

/// <summary>Execução de processos externos (sc.exe). Abstraída para testar a montagem dos argumentos sem alterar o sistema.</summary>
public interface IProcessRunner
{
    ProcessResult Run(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout);
}

public sealed class SystemProcessRunner : IProcessRunner
{
    public ProcessResult Run(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout)
    {
        // ArgumentList (e não uma string): nada passa por shell e cada argumento é escapado corretamente.
        var info = new ProcessStartInfo(fileName) { UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true, CreateNoWindow = true };
        foreach (var argument in arguments)
        {
            info.ArgumentList.Add(argument);
        }

        using var process = Process.Start(info) ?? throw new InvalidOperationException($"Não foi possível iniciar {fileName}.");
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(timeout))
        {
            process.Kill(entireProcessTree: true);
            throw new TimeoutException($"{fileName} excedeu {timeout.TotalSeconds:F0}s.");
        }

        return new ProcessResult(process.ExitCode, output.Result + error.Result);
    }
}

public class ServiceInstallException(string message) : Exception(message);

/// <summary>
/// Cria/atualiza/remove o serviço do Windows via sc.exe (ferramenta documentada do sistema). Idempotente:
/// pode rodar na instalação, no reparo e na atualização. Conta LocalSystem, início automático atrasado,
/// recuperação automática (reinicia após falha em 5 s / 30 s / 60 s).
/// </summary>
public sealed class ServiceInstaller(IProcessRunner runner, string serviceName = "ToolitAgent", Action<TimeSpan>? sleep = null)
{
    public const string DefaultName = "ToolitAgent";
    public const string DisplayName = "Toolit Agent";
    public const string Description = "Inventário e monitoramento do equipamento para o Toolit Manager.";

    private const int ErrorServiceDoesNotExist = 1060;
    private const int ErrorServiceAlreadyRunning = 1056;
    private const int ErrorServiceNotActive = 1062;
    private static readonly TimeSpan CommandTimeout = TimeSpan.FromSeconds(30);

    private readonly Action<TimeSpan> _sleep = sleep ?? Thread.Sleep;

    public string ServiceName { get; } = serviceName;

    public bool Exists()
    {
        var result = Sc("query", ServiceName);
        if (result.ExitCode == 0)
        {
            return true;
        }

        return result.ExitCode == ErrorServiceDoesNotExist ? false : throw Failure("query", result);
    }

    public bool IsRunning()
    {
        var result = Sc("query", ServiceName);

        return result.ExitCode == 0 && Regex.IsMatch(result.Output, @"\b4\s+RUNNING\b"); // "STATE : 4 RUNNING" (rótulo localizado, estado não)
    }

    public void Install(string exePath, TimeSpan? startTimeout = null)
    {
        ValidateExePath(exePath);

        Run(Exists() ? "config" : "create", ServiceName,
            "binPath=", $"\"{exePath}\"", "start=", "delayed-auto", "obj=", "LocalSystem", "DisplayName=", DisplayName);
        Run("description", ServiceName, Description);
        Run("failure", ServiceName, "reset=", "86400", "actions=", "restart/5000/restart/30000/restart/60000");
        Run("failureflag", ServiceName, "1");

        var started = Sc("start", ServiceName);
        if (started.ExitCode != 0 && started.ExitCode != ErrorServiceAlreadyRunning)
        {
            throw Failure("start", started);
        }

        WaitFor(() => IsRunning(), startTimeout ?? TimeSpan.FromSeconds(30), $"O serviço {ServiceName} não chegou ao estado RUNNING.");
    }

    public void Uninstall(TimeSpan? stopTimeout = null)
    {
        if (!Exists())
        {
            return;
        }

        var stopped = Sc("stop", ServiceName);
        if (stopped.ExitCode != 0 && stopped.ExitCode != ErrorServiceNotActive)
        {
            throw Failure("stop", stopped);
        }

        WaitFor(() => !IsRunning(), stopTimeout ?? TimeSpan.FromSeconds(30), $"O serviço {ServiceName} não parou a tempo.");

        var deleted = Sc("delete", ServiceName);
        if (deleted.ExitCode != 0 && deleted.ExitCode != ErrorServiceDoesNotExist)
        {
            throw Failure("delete", deleted);
        }
    }

    /// <summary>Caminho entre aspas vai direto ao Registro do SCM: só aceitamos um caminho absoluto, simples e existente.</summary>
    public static void ValidateExePath(string exePath)
    {
        if (!Path.IsPathRooted(exePath) || exePath.Any(c => char.IsControl(c) || c == '"')
            || !exePath.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) || !File.Exists(exePath))
        {
            throw new ServiceInstallException("Caminho do executável inválido para registrar o serviço.");
        }
    }

    private void Run(string verb, params string[] arguments)
    {
        var result = Sc([verb, .. arguments]);
        if (result.ExitCode != 0)
        {
            throw Failure(verb, result);
        }
    }

    private ProcessResult Sc(params string[] arguments) => runner.Run("sc.exe", arguments, CommandTimeout);

    private void WaitFor(Func<bool> condition, TimeSpan timeout, string failureMessage)
    {
        var waited = TimeSpan.Zero;
        var step = TimeSpan.FromMilliseconds(500);
        while (!condition())
        {
            if (waited >= timeout)
            {
                throw new ServiceInstallException(failureMessage);
            }

            _sleep(step);
            waited += step;
        }
    }

    private static ServiceInstallException Failure(string verb, ProcessResult result) =>
        new($"sc.exe {verb} falhou (código {result.ExitCode}): {result.Output.Trim()}");
}
