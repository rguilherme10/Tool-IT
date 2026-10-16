using System.Diagnostics;
using System.Security.Cryptography;
using AgentToolit.Core.Config;
using AgentToolit.Core.Execution;
using AgentToolit.Core.Execution.Handlers;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Logging;
using AgentToolit.Core.Net;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Transfers;
using AgentToolit.Tests.Support;
using Microsoft.Extensions.Logging;
using Xunit.Abstractions;

namespace AgentToolit.Tests;

/// <summary>Só roda com <c>TOOLIT_E2E_MANAGER</c> definido; sem ele o teste aparece como ignorado (skip).</summary>
public sealed class E2EFactAttribute : FactAttribute
{
    public E2EFactAttribute()
    {
        if (string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable(TransferE2ETests.ManagerVar)))
        {
            Skip = "E2E de transferência: defina TOOLIT_E2E_MANAGER=http://127.0.0.1:<porta> (docs/test-evidence.md).";
        }
    }
}

/// <summary>
/// E2E da task 009: os handlers REAIS file.send/file.fetch, o TaskRunner e o TaskPoller reais, um ManagerClient real
/// fazendo HTTP assinado de verdade contra um Manager LOCAL (php artisan serve) e disco real — mas dentro do processo
/// de teste, NÃO o executável do serviço. Motivo: numa sessão sem Administrador as raízes temporárias (donas do usuário
/// atual) falham a conferência de segurança do <see cref="TransferRootRegistry"/> e o Agent real recusa tudo (o teste
/// também prova isso); aqui as raízes temporárias passam pelo seam de teste <c>TrustedRootsForTests</c>.
///
/// Variáveis: TOOLIT_E2E_MANAGER (só loopback), TOOLIT_E2E_TOKEN (token de registro; só na 1ª execução),
/// TOOLIT_E2E_DIR (opcional: pasta de trabalho sob o %TEMP%, mantida entre execuções para a retomada; sem ela usa uma
/// pasta temporária própria, apagada no fim), TOOLIT_E2E_SECONDS (duração máxima do laço, padrão 60; para antes se
/// existir &lt;dir&gt;\stop ou se a instalação for revogada), TOOLIT_E2E_BPS (banda do Agent, padrão 4 MiB/s).
/// Layout: &lt;dir&gt;\agent (dados do Agent), &lt;dir&gt;\root\Inbox e &lt;dir&gt;\root\Outbox (raízes), &lt;dir&gt;\outside (canário fora das
/// raízes) e as junctions descartáveis Inbox\junction-out / Outbox\junction-out → outside, removidas sem recursão no fim.
/// Nada fora de &lt;dir&gt;. O roteiro dos cenários (navegador, política, interrupções) está em docs/test-evidence.md.
/// </summary>
public class TransferE2ETests(ITestOutputHelper output)
{
    internal const string ManagerVar = "TOOLIT_E2E_MANAGER";

    [E2EFact]
    public async Task Real_handlers_move_files_through_a_real_local_manager()
    {
        var manager = new Uri(Environment.GetEnvironmentVariable(ManagerVar)!.Trim());
        Assert.True(manager.IsLoopback, "TOOLIT_E2E_MANAGER precisa ser loopback (127.0.0.1/localhost/::1).");

        var given = Environment.GetEnvironmentVariable("TOOLIT_E2E_DIR");
        using var own = string.IsNullOrWhiteSpace(given) ? new TempDir() : null;
        var dir = Path.GetFullPath(own?.Path ?? given!.Trim());
        Assert.True(Directory.Exists(dir), "TOOLIT_E2E_DIR precisa existir.");
        Assert.True(PathGuard.IsInside(dir, [Path.TrimEndingDirectorySeparator(Path.GetFullPath(Path.GetTempPath()))]), "TOOLIT_E2E_DIR precisa ficar sob o %TEMP%.");

        var seconds = int.TryParse(Environment.GetEnvironmentVariable("TOOLIT_E2E_SECONDS"), out var s) && s > 0 ? s : 60;
        var bps = long.TryParse(Environment.GetEnvironmentVariable("TOOLIT_E2E_BPS"), out var b) && b > 0 ? b : AgentSettings.DefaultTransferBytesPerSecond;

        var paths = new AgentPaths(Path.Combine(dir, "agent"));
        paths.EnsureCreated(restrictAcl: false);
        var rootBase = Path.Combine(dir, "root");
        var inbox = Path.Combine(rootBase, "Inbox");
        var outbox = Path.Combine(rootBase, "Outbox");
        Directory.CreateDirectory(inbox);
        Directory.CreateDirectory(outbox);

        var settings = new AgentSettings
        {
            ServerUrl = manager.ToString(), TransferRoots = [inbox, outbox], TransferFetchOnlyRoots = [],
            TransferBytesPerSecond = bps, TaskPollSeconds = AgentSettings.MinTaskPollSeconds,
        };

        using var loggers = LoggerFactory.Create(l => l.SetMinimumLevel(LogLevel.Debug)
            .AddProvider(new FileLoggerProvider(paths.LogDirectory, 1)));
        var log = loggers.CreateLogger("AgentToolit.E2E");
        void Note(string text)
        {
            output.WriteLine(text);
            log.LogInformation("{Text}", text);
        }

        // 1) Raízes temporárias SEM o seam: sem elevação, a conferência de dono/DACL as exclui (falha fechada).
        var strict = TransferRootRegistry.Build(settings, loggers.CreateLogger("AgentToolit.Transfers"), ownBase: rootBase);
        Note($"Sem seam: elevado={AgentPaths.IsElevated()} envio={strict.SendRoots.Count} recuperação={strict.FetchRoots.Count}");
        if (!AgentPaths.IsElevated())
        {
            Assert.Empty(strict.SendRoots);
            Assert.Empty(strict.FetchRoots);
        }

        // Pasta FORA das raízes com um arquivo-canário, alcançável só por junctions descartáveis plantadas nas raízes:
        // nada pode ser gravado nem lido através delas.
        var outside = Path.Combine(dir, "outside");
        Directory.CreateDirectory(outside);
        var canary = Path.Combine(outside, "segredo.txt");
        if (!File.Exists(canary))
        {
            await File.WriteAllTextAsync(canary, "canario E2E: este arquivo nunca deve sair desta pasta");
        }
        string[] links = [Path.Combine(inbox, "junction-out"), Path.Combine(outbox, "junction-out")];
        foreach (var link in links.Where(l => !Directory.Exists(l)))
        {
            Note($"{PathGuardTests.CreateDirectoryLink(link, outside)} descartável: {link} -> {outside}");
        }

        // 2) Com o seam (só estes temporários): as mesmas raízes passam a valer.
        TransferRootRegistry.TrustedRootsForTests.Value = [rootBase];
        try
        {
            await RunAgentAsync(dir, manager, settings, paths, rootBase, inbox, outbox, seconds, loggers, Note);
        }
        finally
        {
            TransferRootRegistry.TrustedRootsForTests.Value = null;
            foreach (var link in links.Where(Directory.Exists))
            {
                Directory.Delete(link, recursive: false);   // remove só o link, nunca o alvo
            }
        }

        Assert.Equal(["segredo.txt"], Directory.EnumerateFileSystemEntries(outside).Select(Path.GetFileName).ToArray());
        Note("Pasta fora das raízes intacta: só o canário.");
    }

    private static async Task RunAgentAsync(string dir, Uri manager, AgentSettings settings, AgentPaths paths, string rootBase,
        string inbox, string outbox, int seconds, ILoggerFactory loggers, Action<string> note)
    {
        var registry = TransferRootRegistry.Build(settings, loggers.CreateLogger("AgentToolit.Transfers"), ownBase: rootBase);
        note($"Com seam: envio={registry.SendRoots.Count} recuperação={registry.FetchRoots.Count}");
        Assert.Equal(2, registry.SendRoots.Count);

        var runtime = new AgentRuntime(new AgentStateStore(paths));
        using var identity = AgentIdentity.TryLoad(paths) ?? AgentIdentity.Generate();
        using var http = new HttpClient { BaseAddress = manager, Timeout = TimeSpan.FromSeconds(settings.RequestTimeoutSeconds) };
        var client = new ManagerClient(http, identity, runtime, TimeProvider.System, "0.1.0");

        if (!runtime.IsEnrolled)
        {
            var token = Environment.GetEnvironmentVariable("TOOLIT_E2E_TOKEN");
            Assert.False(string.IsNullOrWhiteSpace(token), "Primeira execução: defina TOOLIT_E2E_TOKEN.");
            var enrolled = await client.EnrollAsync(new EnrollRequest(token!.Trim(), identity.PublicKeyBase64, "E2E-TRANSFER-HARNESS",
                new OsInfo("Windows (E2E)", Environment.OSVersion.Version.ToString(), null, "x64"), "0.1.0", null), CancellationToken.None);
            identity.Save(paths);
            runtime.SetEnrolled(new AgentState { AgentId = enrolled.AgentId, EndpointId = enrolled.EndpointId, EnrolledAt = enrolled.ServerTime });
            await File.WriteAllTextAsync(Path.Combine(dir, "endpoint.txt"), enrolled.EndpointId);
            note($"Registrado: endpoint_id={enrolled.EndpointId}");
        }
        else
        {
            note($"Já registrado: endpoint_id={runtime.State!.EndpointId} revogado={runtime.IsRevoked}");
        }

        ITaskHandler[] handlers =
        [
            new FileSendHandler(client, settings, TimeProvider.System, runtime, registry, new SystemDiskSpace(), loggers.CreateLogger<FileSendHandler>()),
            new FileFetchHandler(client, settings, TimeProvider.System, runtime, registry, loggers.CreateLogger<FileFetchHandler>()),
        ];
        var runner = new TaskRunner(handlers, new TaskCoordinator(TimeProvider.System), TimeProvider.System,
            loggers.CreateLogger<TaskRunner>(), new ManagerProgressSink(client));
        var poller = new TaskPoller(client, runner, runtime, settings, loggers.CreateLogger<TaskPoller>());

        // 3) Laço do Agent: um ciclo de poll por vez (como o TaskWorker), até o prazo, o arquivo "stop" ou a revogação.
        var stop = Path.Combine(dir, "stop");
        var clock = Stopwatch.StartNew();
        while (clock.Elapsed < TimeSpan.FromSeconds(seconds) && !File.Exists(stop) && !runtime.IsRevoked)
        {
            var delay = await poller.TickAsync(CancellationToken.None);
            var wait = delay < TimeSpan.FromSeconds(2) ? delay : TimeSpan.FromSeconds(2);
            if (wait > TimeSpan.Zero)
            {
                await Task.Delay(wait);
            }
        }
        note($"Laço encerrado após {clock.Elapsed.TotalSeconds:F0}s (revogado={runtime.IsRevoked}, stop={File.Exists(stop)}).");

        // 4) Inventário final das raízes (só arquivos do nível de cima; links/pastas não são seguidos).
        foreach (var root in new[] { inbox, outbox })
        {
            foreach (var file in Directory.EnumerateFiles(root).Order(StringComparer.Ordinal))
            {
                await using var stream = File.OpenRead(file);
                note($"{Path.GetFileName(root)}\\{Path.GetFileName(file)} {stream.Length} bytes sha256={Convert.ToHexString(await SHA256.HashDataAsync(stream)).ToLowerInvariant()}");
            }
        }
    }
}
