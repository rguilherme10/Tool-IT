using System.Text.RegularExpressions;
using AgentToolit.Core.Config;
using AgentToolit.Core.Install;
using AgentToolit.Core.Logging;

namespace AgentToolit;

/// <summary>
/// service install --server https://manager --token tk_...   (executado pelo instalador MSI, como SYSTEM/Administrador)
/// service uninstall [--remove-data]
///
/// Códigos de saída: 0 ok · 2 argumentos · 3 sem privilégio · 4 registro no Manager falhou · 5 falha ao configurar o serviço.
/// O token nunca é gravado nem registrado em log; o log da instalação fica em %ProgramData%\Toolit\Agent\logs\install.log.
/// </summary>
internal static partial class ServiceCommands
{
    public const int Ok = 0;
    public const int BadArguments = 2;
    public const int NotElevated = 3;
    public const int EnrollFailed = 4;
    public const int ServiceFailed = 5;

    // O token vai para a linha de comando de um processo: só aceitamos o formato que o Manager emite.
    [GeneratedRegex(@"^tk_[A-Za-z0-9_\-]{16,128}$")]
    private static partial Regex TokenFormat();

    public static async Task<int> RunAsync(string[] args, IProcessRunner? runner = null)
    {
        var verb = args.FirstOrDefault()?.ToLowerInvariant();
        var options = Commands.Parse(args.Skip(1).ToArray());
        var dryRun = options.ContainsKey("dry-run");
        runner ??= dryRun ? new DryRunRunner() : new SystemProcessRunner();

        var paths = Commands.Paths();
        Directory.CreateDirectory(paths.LogDirectory);
        void Log(string message) => WriteLog(paths, message);

        try
        {
            return verb switch
            {
                "install" => await InstallAsync(options, paths, runner, dryRun, Log),
                "uninstall" => Uninstall(options, paths, runner, dryRun, Log),
                _ => Fail(BadArguments, "Uso: service install --server <url> --token <tk_...> | service uninstall [--remove-data] [--dry-run]", Log),
            };
        }
        catch (ServiceInstallException ex)
        {
            return Fail(ServiceFailed, ex.Message, Log);
        }
        catch (Exception ex) when (ex is UnauthorizedAccessException or IOException or TimeoutException)
        {
            return Fail(ServiceFailed, $"{ex.GetType().Name}: {ex.Message}", Log);
        }
    }

    private static async Task<int> InstallAsync(Dictionary<string, string?> options, AgentPaths paths, IProcessRunner runner, bool dryRun, Action<string> log)
    {
        if (!dryRun && !AgentPaths.IsElevated())
        {
            return Fail(NotElevated, "A instalação do serviço exige privilégios de Administrador (ou SYSTEM).", log);
        }

        var exe = Environment.ProcessPath ?? "";
        log($"Instalando o serviço a partir de {exe}{(dryRun ? " (simulação)" : "")}");

        if (File.Exists(paths.StateFile))
        {
            log("Equipamento já registrado: identidade mantida (nenhum token necessário).");
        }
        else
        {
            var token = options.GetValueOrDefault("token") ?? Environment.GetEnvironmentVariable(Commands.TokenEnvVar);
            if (string.IsNullOrWhiteSpace(token) || !TokenFormat().IsMatch(token.Trim()))
            {
                return Fail(BadArguments, "Primeiro registro: informe --token (formato tk_...) emitido em 'Novo agente' no painel.", log);
            }

            if (dryRun)
            {
                log("[simulação] registraria o equipamento no Manager com o token informado.");
            }
            else
            {
                Environment.SetEnvironmentVariable(Commands.TokenEnvVar, token.Trim()); // só deste processo
                var code = await Commands.EnrollAsync(["--server", options.GetValueOrDefault("server") ?? ""]);
                Environment.SetEnvironmentVariable(Commands.TokenEnvVar, null);
                if (code != 0)
                {
                    return Fail(EnrollFailed, "Registro no Manager falhou (token inválido/expirado/usado, URL incorreta ou Manager inacessível).", log);
                }

                log("Equipamento registrado no Manager.");
            }
        }

        var installer = new ServiceInstaller(runner);
        if (dryRun)
        {
            ((DryRunRunner)runner).Install(installer, exe);

            return Ok;
        }

        installer.Install(exe);
        log($"Serviço {installer.ServiceName} instalado e em execução.");

        return Ok;
    }

    private static int Uninstall(Dictionary<string, string?> options, AgentPaths paths, IProcessRunner runner, bool dryRun, Action<string> log)
    {
        if (!dryRun && !AgentPaths.IsElevated())
        {
            return Fail(NotElevated, "A remoção do serviço exige privilégios de Administrador (ou SYSTEM).", log);
        }

        var installer = new ServiceInstaller(runner);
        installer.Uninstall();
        log($"Serviço {installer.ServiceName} removido{(dryRun ? " (simulação)" : "")}.");

        if (options.ContainsKey("remove-data") && !dryRun)
        {
            // A identidade só é apagada a pedido explícito; no MSI a remoção/atualização MANTÉM os dados.
            foreach (var file in new[] { paths.IdentityFile, paths.StateFile, paths.ConfigFile, paths.QueueFile })
            {
                File.Delete(file);
            }

            log("Identidade e configuração locais removidas. Revogue a instalação no painel do Manager.");
        }
        else
        {
            log("Dados locais mantidos (identidade do agente).");
        }

        return Ok;
    }

    private static int Fail(int code, string message, Action<string> log)
    {
        log("ERRO: " + message);
        Console.Error.WriteLine(message);

        return code;
    }

    private static void WriteLog(AgentPaths paths, string message)
    {
        Console.WriteLine(message);
        try
        {
            File.AppendAllText(Path.Combine(paths.LogDirectory, "install.log"),
                string.Create(System.Globalization.CultureInfo.InvariantCulture, $"{DateTimeOffset.UtcNow:yyyy-MM-ddTHH:mm:ssZ} {LogRedactor.Redact(message)}{Environment.NewLine}"));
        }
        catch (IOException)
        {
            // Log de instalação é melhor-esforço: nunca impede a instalação.
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    /// <summary>--dry-run: mostra os comandos sc.exe que seriam executados, sem tocar no sistema.</summary>
    private sealed class DryRunRunner : IProcessRunner
    {
        public ProcessResult Run(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout)
        {
            Console.WriteLine($"[simulação] {fileName} {string.Join(' ', arguments.Select(a => a.Contains(' ') ? $"\"{a}\"" : a))}");

            // query → "serviço não existe"; demais comandos → sucesso.
            return new ProcessResult(arguments.Count > 0 && arguments[0] == "query" ? 1060 : 0, "");
        }

        public void Install(ServiceInstaller installer, string exe)
        {
            // Em simulação não validamos a existência do arquivo; apenas exibimos a sequência de comandos.
            foreach (var step in new[] { "create", "description", "failure", "failureflag", "start" })
            {
                Run("sc.exe", [step, installer.ServiceName, "..."], TimeSpan.Zero);
            }

            Console.WriteLine($"[simulação] binPath= \"{exe}\" start= delayed-auto obj= LocalSystem");
        }
    }
}
