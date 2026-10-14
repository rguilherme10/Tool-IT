using AgentToolit;
using AgentToolit.Collectors;
using AgentToolit.Collectors.Inventory;
using AgentToolit.Collectors.Metrics;
using AgentToolit.Collectors.Wmi;
using AgentToolit.Core.Config;
using AgentToolit.Core.Host;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Logging;
using AgentToolit.Core.Net;
using AgentToolit.Core.Queue;
using AgentToolit.Core.Services;

// Comandos administrativos (instalador/operador). Sem argumentos: executa como serviço do Windows.
Console.OutputEncoding = new System.Text.UTF8Encoding(false);

switch (args.FirstOrDefault()?.ToLowerInvariant())
{
    case "enroll":
        return await Commands.EnrollAsync(args[1..]);
    case "status":
        return Commands.Status();
    case "rotate-key":
        return await Commands.RotateKeyAsync();
    case "--version":
        Console.WriteLine(Commands.Version);

        return 0;
}


var paths = Commands.Paths();
paths.EnsureCreated(restrictAcl: Environment.GetEnvironmentVariable("TOOLIT_AGENT_DATA") is null); // ACL só se elevado/SYSTEM

var settings = new AgentSettingsStore(paths).Load();

var builder = Host.CreateApplicationBuilder(new HostApplicationBuilderSettings { Args = args, ContentRootPath = AppContext.BaseDirectory });
builder.Services.AddWindowsService(options => options.ServiceName = "ToolitAgent");

builder.Logging.ClearProviders();
builder.Logging.AddProvider(new FileLoggerProvider(paths.LogDirectory, settings.LogRetentionDays));
if (Environment.UserInteractive)
{
    builder.Logging.AddSimpleConsole(o => o.SingleLine = true);
}

builder.Logging.AddEventLog(o => o.SourceName = "ToolitAgent");
builder.Logging.AddFilter<Microsoft.Extensions.Logging.EventLog.EventLogLoggerProvider>(level => level >= LogLevel.Warning);

builder.Services.AddSingleton(TimeProvider.System);
builder.Services.AddSingleton(paths);
builder.Services.AddSingleton(settings);
builder.Services.AddSingleton(new AgentStateStore(paths));
builder.Services.AddSingleton<AgentRuntime>();
builder.Services.AddSingleton(_ => new OutboundQueue(paths.QueueFile, settings.QueueMaxItems, settings.QueueMaxBytes));

// Identidade: sem chave local o serviço sobe, mas fica ocioso até o enrollment (e avisa no log).
builder.Services.AddSingleton(sp =>
    AgentIdentity.TryLoad(paths) ?? AgentIdentity.Generate());

builder.Services.AddSingleton(sp =>
{
    if (!ServerUrlPolicy.TryValidate(settings.ServerUrl, out var uri, out var error))
    {
        sp.GetRequiredService<ILogger<AgentRuntime>>().LogWarning("Servidor não configurado ou inválido ({Error}); execute 'enroll'.", error);
        uri = new Uri("https://invalid.local/");
    }

    var http = new HttpClient { BaseAddress = uri, Timeout = TimeSpan.FromSeconds(settings.RequestTimeoutSeconds) };

    return new ManagerClient(http, sp.GetRequiredService<AgentIdentity>(), sp.GetRequiredService<AgentRuntime>(),
        sp.GetRequiredService<TimeProvider>(), Commands.Version);
});

builder.Services.AddSingleton<IWmiQuery, WmiQuery>();
builder.Services.AddSingleton<ISystemProbe, WindowsSystemProbe>();
builder.Services.AddSingleton<ISystemProbeMemory, WindowsMemoryProbe>();
builder.Services.AddSingleton<IHostFacts, WindowsHostFacts>();
builder.Services.AddSingleton<IUninstallSource, RegistryUninstallSource>();
builder.Services.AddSingleton<ISectionCollector, SystemCollector>();
builder.Services.AddSingleton<ISectionCollector, HardwareCollector>();
builder.Services.AddSingleton<ISectionCollector, SoftwareCollector>();
builder.Services.AddSingleton<ISectionCollector, NetworkCollector>();
builder.Services.AddSingleton<ISectionCollector, PeripheralCollector>();
builder.Services.AddSingleton<InventoryCollector>();
builder.Services.AddSingleton<MetricsCollector>();
builder.Services.AddSingleton<CollectionService>();
builder.Services.AddSingleton<HeartbeatService>();
builder.Services.AddSingleton<SenderService>();

builder.Services.AddHostedService<HeartbeatWorker>();
builder.Services.AddHostedService<MetricsWorker>();
builder.Services.AddHostedService<InventoryWorker>();
builder.Services.AddHostedService<SenderWorker>();

var host = builder.Build();
host.Services.GetRequiredService<ILoggerFactory>().CreateLogger("AgentToolit")
    .LogInformation("Toolit Agent {Version} iniciado (registrado={Enrolled}).", Commands.Version, host.Services.GetRequiredService<AgentRuntime>().IsEnrolled);

await host.RunAsync();

return 0;
