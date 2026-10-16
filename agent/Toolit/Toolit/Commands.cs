using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using AgentToolit.Core.Config;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using Microsoft.Win32;

namespace AgentToolit;

/// <summary>Comandos de linha de comando executados pelo instalador/administrador (não pelo serviço).</summary>
internal static class Commands
{
    public const string TokenEnvVar = "TOOLIT_ENROLLMENT_TOKEN";

    public static string Version => typeof(Commands).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";

    public static AgentPaths Paths() =>
        Environment.GetEnvironmentVariable("TOOLIT_AGENT_DATA") is { Length: > 0 } custom ? new AgentPaths(custom) : AgentPaths.Default();

    /// <summary>
    /// enroll --server https://manager --token tk_... [--force]
    /// O token também pode vir de TOOLIT_ENROLLMENT_TOKEN (preferível: argumentos aparecem na lista de processos).
    /// O token NUNCA é gravado em disco nem em log.
    /// </summary>
    public static async Task<int> EnrollAsync(string[] args)
    {
        var options = Parse(args);
        var token = options.GetValueOrDefault("token") ?? Environment.GetEnvironmentVariable(TokenEnvVar);

        if (!ServerUrlPolicy.TryValidate(options.GetValueOrDefault("server"), out var serverUri, out var urlError))
        {
            return Fail(urlError ?? "URL inválida.");
        }

        if (string.IsNullOrWhiteSpace(token))
        {
            return Fail($"Informe --token ou a variável {TokenEnvVar}.");
        }

        var paths = Paths();
        var customDir = Environment.GetEnvironmentVariable("TOOLIT_AGENT_DATA") is not null;
        try
        {
            var restricted = paths.EnsureCreated(restrictAcl: !customDir && !options.ContainsKey("no-acl"));
            paths.AssertWritable(); // falha ANTES de consumir o token (que é de uso único)
            if (!restricted && !customDir)
            {
                Console.Error.WriteLine("Aviso: execução sem privilégios de administrador — a ACL restrita do diretório de dados não foi aplicada.");
            }
        }
        catch (Exception ex) when (ex is UnauthorizedAccessException or IOException)
        {
            return Fail($"Sem permissão para gravar em {paths.Root}: {ex.Message}. Execute como Administrador.");
        }

        if (!options.ContainsKey("force") && (File.Exists(paths.IdentityFile) || File.Exists(paths.StateFile)))
        {
            return Fail("Este equipamento já está registrado. Use --force para registrar de novo (a instalação anterior deve ser revogada no Manager).");
        }

        using var identity = AgentIdentity.Generate();
        var settings = new AgentSettings { ServerUrl = serverUri!.ToString() };
        using var http = new HttpClient { BaseAddress = serverUri, Timeout = TimeSpan.FromSeconds(settings.RequestTimeoutSeconds) };
        var runtime = new AgentRuntime(new AgentStateStore(paths));
        var client = new ManagerClient(http, identity, runtime, TimeProvider.System, Version);

        try
        {
            var response = await client.EnrollAsync(new EnrollRequest(
                token.Trim(), identity.PublicKeyBase64, Environment.MachineName, DetectOs(), Version, MachineFingerprint()), CancellationToken.None);

            settings.ApplyServerConfig(response.Config);
            try
            {
                identity.Save(paths);
                new AgentSettingsStore(paths).Save(settings);
                runtime.SetEnrolled(new AgentState { AgentId = response.AgentId, EndpointId = response.EndpointId, EnrolledAt = response.ServerTime });
            }
            catch (Exception ex) when (ex is UnauthorizedAccessException or IOException or System.Security.Cryptography.CryptographicException)
            {
                // O Manager já criou a instalação e o token foi consumido: o operador precisa saber para revogá-la.
                return Fail($"Registrado no Manager, mas falhou ao gravar o estado local ({ex.GetType().Name}). Revogue a instalação agent_id={response.AgentId} no painel e gere um novo token.");
            }

            // Dados pendentes de uma instalação anterior (possivelmente revogada) não pertencem à nova identidade.
            foreach (var suffix in new[] { "", "-wal", "-shm" })
            {
                try
                {
                    File.Delete(paths.QueueFile + suffix);
                }
                catch (IOException)
                {
                    // Serviço ainda em execução: o instalador deve pará-lo antes de registrar.
                }
            }

            Console.WriteLine($"Registrado. agent_id={response.AgentId} endpoint_id={response.EndpointId}");

            return 0;
        }
        catch (ManagerRejectedException ex)
        {
            return Fail($"Registro recusado pelo Manager: {ex.Code}");
        }
        catch (ManagerUnavailableException ex)
        {
            return Fail($"Manager inacessível: {ex.Message}");
        }
    }

    /// <summary>status — mostra o estado local sem expor segredos.</summary>
    public static int Status()
    {
        var paths = Paths();
        var state = new AgentStateStore(paths).Load();
        var settings = new AgentSettingsStore(paths).Load();

        Console.WriteLine($"Versão: {Version}");
        Console.WriteLine($"Dados: {paths.Root}");
        Console.WriteLine($"Servidor: {(settings.ServerUrl.Length > 0 ? settings.ServerUrl : "(não configurado)")}");
        Console.WriteLine($"Registrado: {(state is null ? "não" : $"sim (agent_id={state.AgentId})")}");
        Console.WriteLine($"Revogado: {(state?.Revoked == true ? "SIM — faça um novo enrollment" : "não")}");
        Console.WriteLine($"Identidade (chave): {(File.Exists(paths.IdentityFile) ? "presente" : "ausente")}");
        Console.WriteLine($"Fila local: {(Core.Queue.OutboundQueue.CountPending(paths.QueueFile)?.ToString() ?? "sem arquivo")} item(ns) pendente(s)");

        return 0;
    }

    /// <summary>rotate-key — gera nova chave, registra no Manager (assinando com a antiga) e só então substitui a local.</summary>
    public static async Task<int> RotateKeyAsync()
    {
        var paths = Paths();
        var settings = new AgentSettingsStore(paths).Load();
        var runtime = new AgentRuntime(new AgentStateStore(paths));
        var current = AgentIdentity.TryLoad(paths);

        if (current is null || !runtime.IsEnrolled || !ServerUrlPolicy.TryValidate(settings.ServerUrl, out var uri, out _))
        {
            return Fail("Agent não registrado.");
        }

        using (current)
        using (var next = AgentIdentity.Generate())
        using (var http = new HttpClient { BaseAddress = uri, Timeout = TimeSpan.FromSeconds(settings.RequestTimeoutSeconds) })
        {
            var client = new ManagerClient(http, current, runtime, TimeProvider.System, Version);
            try
            {
                var response = await client.RotateKeyAsync(new RotateKeyRequest(next.PublicKeyBase64), CancellationToken.None);
                next.Save(paths);
                Console.WriteLine($"Chave rotacionada. A chave anterior vale até {response.PreviousKeyValidUntil:u}. Reinicie o serviço.");

                return 0;
            }
            catch (Exception ex) when (ex is ManagerRejectedException or ManagerUnavailableException or ManagerAuthException or AgentRevokedException)
            {
                return Fail($"Rotação não concluída ({ex.GetType().Name}); a chave local não foi alterada.");
            }
        }
    }

    private static OsInfo DetectOs()
    {
        string? name = null, build = null;
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
            name = key?.GetValue("ProductName") as string;
            build = key?.GetValue("CurrentBuild") as string;
        }
        catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException)
        {
            // Sem acesso ao Registro: segue só com o que a runtime informa.
        }

        // O Registro do Windows 11 ainda diz "Windows 10" em ProductName; o build (>= 22000) desempata.
        if (name is not null && int.TryParse(build, System.Globalization.NumberStyles.Integer, System.Globalization.CultureInfo.InvariantCulture, out var b) && b >= 22000)
        {
            name = name.Replace("Windows 10", "Windows 11", StringComparison.Ordinal);
        }

        var arch = RuntimeInformation.OSArchitecture switch { Architecture.Arm64 => "arm64", Architecture.X86 => "x86", _ => "x64" };

        return new OsInfo(name, Environment.OSVersion.Version.ToString(), build, arch);
    }

    /// <summary>Dado AUXILIAR (nunca identidade): hash do MachineGuid, útil para o operador reconhecer reinstalações.</summary>
    private static string? MachineFingerprint()
    {
        try
        {
            using var key = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64).OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");

            return key?.GetValue("MachineGuid") is string guid
                ? Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(guid))).ToLowerInvariant()
                : null;
        }
        catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException)
        {
            return null;
        }
    }

    internal static Dictionary<string, string?> Parse(string[] args)
    {
        var result = new Dictionary<string, string?>(StringComparer.OrdinalIgnoreCase);
        for (var i = 0; i < args.Length; i++)
        {
            if (!args[i].StartsWith("--", StringComparison.Ordinal))
            {
                continue;
            }

            var key = args[i][2..];
            result[key] = i + 1 < args.Length && !args[i + 1].StartsWith("--", StringComparison.Ordinal) ? args[++i] : "true";
        }

        return result;
    }

    private static int Fail(string message)
    {
        Console.Error.WriteLine(message);

        return 1;
    }
}
