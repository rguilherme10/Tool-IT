using System.Text.Json;
using AgentToolit.Collectors;
using AgentToolit.Collectors.Inventory;
using AgentToolit.Collectors.Metrics;
using AgentToolit.Collectors.Wmi;
using AgentToolit.Core.Protocol;
using AgentToolit.Tests.Support;
using Xunit.Abstractions;

namespace AgentToolit.Tests;

internal sealed class FakeWmi(Func<string, string, IReadOnlyList<IReadOnlyDictionary<string, object?>>> handler) : IWmiQuery
{
    public IReadOnlyList<IReadOnlyDictionary<string, object?>> Query(string scope, string wql, TimeSpan timeout) => handler(scope, wql);

    public static IReadOnlyDictionary<string, object?> Row(params (string Key, object? Value)[] values) =>
        values.ToDictionary(v => v.Key, v => v.Value, StringComparer.OrdinalIgnoreCase);

    public static IReadOnlyList<IReadOnlyDictionary<string, object?>> Rows(params IReadOnlyDictionary<string, object?>[] rows) => rows;
}

public class SoftwareParserTests
{
    private static UninstallEntry Entry(string source, params (string K, object? V)[] values) =>
        new(source, values.ToDictionary(v => v.K, v => v.V, StringComparer.OrdinalIgnoreCase));

    [Fact]
    public void Keeps_real_applications_and_normalizes_fields()
    {
        var items = SoftwareParser.Parse([
            Entry("HKLM64", ("DisplayName", "Google Chrome"), ("DisplayVersion", "130.0.1"), ("Publisher", "Google LLC"), ("InstallDate", "20240131")),
            Entry("HKLM32", ("DisplayName", "7-Zip"), ("DisplayVersion", "24.08")),
            Entry("HKU", ("DisplayName", "VS Code"), ("InstallDate", "2024-01-31")),
        ]);

        Assert.Equal(["7-Zip", "Google Chrome", "VS Code"], items.Select(i => i.Name));
        var chrome = items.Single(i => i.Name == "Google Chrome");
        Assert.Equal(("130.0.1", "Google LLC", "2024-01-31", "x64"), (chrome.Version, chrome.Publisher, chrome.InstallDate, chrome.Arch));
        Assert.Equal("x86", items.Single(i => i.Name == "7-Zip").Arch);
        Assert.Null(items.Single(i => i.Name == "VS Code").InstallDate);   // formato inesperado é ignorado, não inventado
        Assert.Null(items.Single(i => i.Name == "VS Code").Arch);
    }

    [Fact]
    public void Skips_system_components_updates_and_nameless_entries()
    {
        var items = SoftwareParser.Parse([
            Entry("HKLM64", ("DisplayName", "Componente"), ("SystemComponent", 1)),
            Entry("HKLM64", ("DisplayName", "KB5001234"), ("ParentKeyName", "OperatingSystem")),
            Entry("HKLM64", ("DisplayName", "Patch X"), ("ParentDisplayName", "Windows")),
            Entry("HKLM64", ("DisplayName", "Update Y"), ("ReleaseType", "Security Update")),
            Entry("HKLM64", ("DisplayName", "   ")),
            Entry("HKLM64", ("DisplayVersion", "1.0")),
            Entry("HKLM64", ("DisplayName", "Visível")),
        ]);

        Assert.Equal(["Visível"], items.Select(i => i.Name));
    }

    [Fact]
    public void Deduplicates_identical_entries_but_keeps_same_app_from_different_sources()
    {
        var items = SoftwareParser.Parse([
            Entry("HKLM64", ("DisplayName", "App"), ("DisplayVersion", "1")),
            Entry("HKLM64", ("DisplayName", "app"), ("DisplayVersion", "1")),
            Entry("HKLM32", ("DisplayName", "App"), ("DisplayVersion", "1")),
        ]);

        Assert.Equal(2, items.Count);
    }

    [Fact]
    public void Strips_control_characters_and_truncates_long_values()
    {
        var item = Assert.Single(SoftwareParser.Parse([
            Entry("HKLM64", ("DisplayName", "Mal\0icioso\r\n" + new string('x', 400)), ("Publisher", "Pub\u0007")),
        ]));

        Assert.DoesNotContain(item.Name, c => char.IsControl(c));
        Assert.Equal(255, item.Name.Length);
        Assert.Equal("Pub", item.Publisher);
    }

    [Fact]
    public void Caps_the_number_of_items()
    {
        var many = Enumerable.Range(0, SoftwareParser.MaxItems + 50).Select(i => Entry("HKLM64", ("DisplayName", "App " + i)));

        Assert.Equal(SoftwareParser.MaxItems, SoftwareParser.Parse(many).Count);
    }

    [Fact]
    public void Collector_reports_truncation()
    {
        var source = new FakeSource(Enumerable.Range(0, SoftwareParser.MaxItems + 1).Select(i => Entry("HKLM64", ("DisplayName", "App " + i))));

        var result = new SoftwareCollector(source).Collect(default);
        var json = JsonSerializer.SerializeToElement(result.Data, Json.Options);

        Assert.True(json.GetProperty("truncated").GetBoolean());
    }

    private sealed class FakeSource(IEnumerable<UninstallEntry> entries) : IUninstallSource
    {
        public IEnumerable<UninstallEntry> Read() => entries;
    }
}

public class WmiCollectorTests
{
    private static JsonElement Data(SectionResult r) => JsonSerializer.SerializeToElement(r.Data, Json.Options);

    private static readonly FakeMemory Memory = new(16384);

    private sealed class FakeMemory(long? mb) : ISystemProbeMemory
    {
        public long? ReadMemoryTotalMb() => mb;
    }

    [Theory]
    [InlineData("To Be Filled By O.E.M.")]
    [InlineData("Default string")]
    [InlineData("  none ")]
    [InlineData("0")]
    public void Placeholder_serials_are_discarded(string value) => Assert.Null(SerialFilter.Clean(value));

    [Fact]
    public void Real_serials_are_kept() => Assert.Equal("ABC123", SerialFilter.Clean(" ABC123 "));

    [Fact]
    public void Hardware_collects_each_component_and_reports_none_missing()
    {
        var wmi = new FakeWmi((_, q) => q switch
        {
            _ when q.Contains("Win32_Processor") => FakeWmi.Rows(FakeWmi.Row(("Name", "Intel i7"), ("NumberOfCores", 10u), ("NumberOfLogicalProcessors", 12u), ("MaxClockSpeed", 1700u))),
            _ when q.Contains("Win32_DiskDrive") => FakeWmi.Rows(FakeWmi.Row(("Model", "NVMe"), ("SerialNumber", "To Be Filled By O.E.M."), ("Size", 512_110_190_592UL), ("InterfaceType", "SCSI"))),
            _ when q.Contains("Win32_VideoController") => FakeWmi.Rows(FakeWmi.Row(("Name", "Iris Xe"), ("DriverVersion", "31.0"), ("AdapterRAM", 134_217_728u))),
            _ when q.Contains("Win32_BaseBoard") => FakeWmi.Rows(FakeWmi.Row(("Manufacturer", "Dell"), ("Product", "0ABC"), ("SerialNumber", "BB1"))),
            _ when q.Contains("Win32_BIOS") => FakeWmi.Rows(FakeWmi.Row(("Manufacturer", "Dell"), ("SMBIOSBIOSVersion", "1.12"), ("ReleaseDate", "20240301000000.000000+000"))),
            _ => FakeWmi.Rows(),
        });

        var data = Data(new HardwareCollector(wmi, Memory).Collect(default));

        Assert.Equal("Intel i7", data.GetProperty("cpus")[0].GetProperty("name").GetString());
        Assert.Equal(10, data.GetProperty("cpus")[0].GetProperty("cores").GetInt64());
        Assert.Equal(16384, data.GetProperty("memory_total_mb").GetInt64());
        Assert.Equal(476.9, data.GetProperty("disks")[0].GetProperty("size_gb").GetDouble());
        Assert.False(data.GetProperty("disks")[0].TryGetProperty("serial", out _), "serial placeholder descartado");
        Assert.Equal(128, data.GetProperty("gpus")[0].GetProperty("vram_mb").GetInt64());
        Assert.Equal("2024-03-01", data.GetProperty("bios").GetProperty("release_date").GetString());
        Assert.Empty(data.GetProperty("not_detected").EnumerateArray());
    }

    [Fact]
    public void Components_that_cannot_be_queried_are_listed_as_not_detected_with_reason()
    {
        var wmi = new FakeWmi((_, q) => q.Contains("Win32_VideoController")
            ? throw new UnauthorizedAccessException()
            : q.Contains("Win32_BIOS") ? throw new TimeoutException()
            : FakeWmi.Rows());

        var data = Data(new HardwareCollector(wmi, new FakeMemory(null)).Collect(default));

        var missing = data.GetProperty("not_detected").EnumerateArray().Select(e => (e.GetProperty("component").GetString(), e.GetProperty("reason").GetString())).ToList();
        Assert.Contains(("gpus", "access_denied"), missing);
        Assert.Contains(("bios", "query_failed"), missing);
        Assert.Contains(("memory", "query_failed"), missing);
        Assert.Empty(data.GetProperty("cpus").EnumerateArray());   // consultou e não há CPU ≠ não conseguiu consultar
    }

    [Fact]
    public void System_section_strips_vendor_prefix_and_distinguishes_domain_from_workgroup()
    {
        FakeWmi Make(bool domain) => new((_, q) => q switch
        {
            _ when q.Contains("Win32_ComputerSystem") => FakeWmi.Rows(FakeWmi.Row(("Manufacturer", "Dell Inc."), ("Model", "Latitude"), ("Domain", domain ? "empresa.local" : "WORKGROUP"), ("PartOfDomain", domain))),
            _ when q.Contains("Win32_OperatingSystem") => FakeWmi.Rows(FakeWmi.Row(("Caption", "Microsoft Windows 11 Pro"), ("Version", "10.0.26100"), ("BuildNumber", "26100"))),
            _ when q.Contains("Win32_BIOS") => FakeWmi.Rows(FakeWmi.Row(("SerialNumber", "SN1"))),
            _ => FakeWmi.Rows(),
        });

        var joined = Data(new SystemCollector(Make(true), TimeProvider.System).Collect(default));
        Assert.Equal("Windows 11 Pro", joined.GetProperty("os").GetProperty("name").GetString());
        Assert.Equal("empresa.local", joined.GetProperty("domain").GetString());
        Assert.False(joined.TryGetProperty("workgroup", out _));
        Assert.Equal("SN1", joined.GetProperty("serial").GetString());

        var workgroup = Data(new SystemCollector(Make(false), TimeProvider.System).Collect(default));
        Assert.Equal("WORKGROUP", workgroup.GetProperty("workgroup").GetString());
        Assert.False(workgroup.TryGetProperty("domain", out _));
    }

    [Fact]
    public void System_section_is_unavailable_when_windows_returns_no_os_info()
    {
        var result = new SystemCollector(new FakeWmi((_, _) => FakeWmi.Rows()), TimeProvider.System).Collect(default);

        Assert.Equal(SectionResult.Unavailable, result.Status);
    }

    [Fact]
    public void Peripherals_decode_wmi_character_arrays_and_survive_a_partial_failure()
    {
        ushort[] Chars(string s) => s.Select(c => (ushort)c).Concat([(ushort)0, (ushort)0]).ToArray();
        var wmi = new FakeWmi((scope, q) => q.Contains("WmiMonitorID")
            ? FakeWmi.Rows(FakeWmi.Row(("UserFriendlyName", Chars("DELL U2723QE")), ("ManufacturerName", Chars("DEL")), ("SerialNumberID", Chars("M1")), ("YearOfManufacture", (ushort)2023)))
            : throw new UnauthorizedAccessException());

        var result = new PeripheralCollector(wmi).Collect(default);
        var data = Data(result);

        Assert.Equal(SectionResult.Ok, result.Status);
        Assert.Equal("DELL U2723QE", data.GetProperty("monitors")[0].GetProperty("name").GetString());
        Assert.Equal(2023, data.GetProperty("monitors")[0].GetProperty("year").GetInt64());
        Assert.False(data.TryGetProperty("printers", out _));
        Assert.Equal("printers", data.GetProperty("not_detected")[0].GetProperty("component").GetString());
    }

    [Fact]
    public void Peripherals_are_unavailable_when_every_source_fails()
    {
        var result = new PeripheralCollector(new FakeWmi((_, _) => throw new UnauthorizedAccessException())).Collect(default);

        Assert.Equal(SectionResult.Unavailable, result.Status);
        Assert.Equal("access_denied", result.Reason);
    }

    [Theory]
    [InlineData("AABBCCDDEEFF", "AA:BB:CC:DD:EE:FF")]
    [InlineData("", null)]
    public void Mac_is_formatted_with_colons(string hex, string? expected)
    {
        var mac = hex.Length == 0 ? System.Net.NetworkInformation.PhysicalAddress.None : System.Net.NetworkInformation.PhysicalAddress.Parse(hex);

        Assert.Equal(expected, NetworkCollector.FormatMac(mac));
    }
}

public class InventoryCollectorTests
{
    private sealed class Stub(string name, Func<SectionResult> run) : ISectionCollector
    {
        public string Name => name;

        public SectionResult Collect(CancellationToken ct) => run();
    }

    [Fact]
    public async Task One_failing_section_does_not_break_the_others_and_leaks_no_message()
    {
        var collector = new InventoryCollector([
            new Stub("system", () => SectionResult.Success(new { ok = true })),
            new Stub("hardware", () => throw new InvalidOperationException("C:\\Users\\maria\\segredo.txt")),
            new Stub("software", () => throw new UnauthorizedAccessException()),
        ], TimeProvider.System);

        var snapshot = await collector.CollectAsync(default);

        Assert.Equal("ok", snapshot.Sections["system"].Status);
        Assert.Equal(("error", "InvalidOperationException"), (snapshot.Sections["hardware"].Status, snapshot.Sections["hardware"].Reason));
        Assert.Equal(("unavailable", "access_denied"), (snapshot.Sections["software"].Status, snapshot.Sections["software"].Reason));
        Assert.DoesNotContain("maria", JsonSerializer.Serialize(snapshot, Json.Options));
        Assert.True(Guid.TryParse(snapshot.SnapshotId, out _));
    }

    [Fact]
    public async Task A_hung_section_times_out_without_blocking_the_inventory()
    {
        var collector = new InventoryCollector([
            new Stub("hardware", () => { Thread.Sleep(6000); return SectionResult.Success(new { }); }),
            new Stub("network", () => SectionResult.Success(new { })),
        ], TimeProvider.System, sectionTimeout: TimeSpan.FromMilliseconds(500));

        var started = DateTime.UtcNow;
        var snapshot = await collector.CollectAsync(default);

        Assert.Equal(("error", "timeout"), (snapshot.Sections["hardware"].Status, snapshot.Sections["hardware"].Reason));
        Assert.Equal("ok", snapshot.Sections["network"].Status);
        Assert.True(DateTime.UtcNow - started < TimeSpan.FromSeconds(5), "o inventário não pode esperar a seção travada terminar");
    }

    [Fact]
    public async Task Cancellation_propagates()
    {
        using var cts = new CancellationTokenSource();
        await cts.CancelAsync();
        var collector = new InventoryCollector([new Stub("system", () => SectionResult.Success(new { }))], TimeProvider.System);

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => collector.CollectAsync(cts.Token));
    }
}

public class MetricsCollectorTests
{
    private sealed class FakeProbe : ISystemProbe
    {
        public Queue<CpuTimes?> Cpu { get; } = new();

        public CpuTimes? ReadCpuTimes() => Cpu.Count > 0 ? Cpu.Dequeue() : null;

        public MemoryInfo? ReadMemory() => new(16384, 4096);

        public IReadOnlyList<VolumeMetric> ReadVolumes() => [new VolumeMetric("C:", 500, 250)];

        public long UptimeSeconds() => 7200;
    }

    [Theory]
    [InlineData(0UL, 0UL, 0UL, 1000UL, 800UL, 100UL, 20.0)]    // Δkernel 800 (200 ocioso) + Δusuário 100...
    public void Cpu_percent_uses_idle_inside_kernel(ulong i0, ulong k0, ulong u0, ulong k1, ulong i1, ulong u1, double _)
    {
        // Δidle=800, Δkernel=1000 (inclui idle), Δuser=100 → total=1100, uso=(1100-800)/1100=27,3 %
        var cpu = MetricsCollector.CpuPercent(new CpuTimes(i0, k0, u0), new CpuTimes(i1, k1, u1));

        Assert.Equal(27.3, cpu);
    }

    [Fact]
    public void Cpu_percent_is_null_when_no_time_elapsed_or_counters_regress()
    {
        Assert.Null(MetricsCollector.CpuPercent(new CpuTimes(1, 1, 1), new CpuTimes(1, 1, 1)));
        Assert.Null(MetricsCollector.CpuPercent(new CpuTimes(1, 100, 100), new CpuTimes(1, 50, 50)));
        Assert.Null(MetricsCollector.CpuPercent(null, new CpuTimes(1, 1, 1)));
        Assert.Equal(0.0, MetricsCollector.CpuPercent(new CpuTimes(0, 0, 0), new CpuTimes(1000, 1000, 0)));
        Assert.Equal(100.0, MetricsCollector.CpuPercent(new CpuTimes(0, 0, 0), new CpuTimes(0, 1000, 0)));
    }

    [Fact]
    public async Task Collects_a_complete_sample_with_unique_ids_and_no_fabricated_temperature()
    {
        var probe = new FakeProbe();
        probe.Cpu.Enqueue(new CpuTimes(0, 0, 0));
        probe.Cpu.Enqueue(new CpuTimes(500, 1000, 0));
        probe.Cpu.Enqueue(new CpuTimes(900, 2000, 0));
        var collector = new MetricsCollector(probe, TimeProvider.System);

        var first = await collector.CollectAsync(default);
        var second = await collector.CollectAsync(default);

        Assert.Equal(50.0, first.CpuPercent);
        Assert.Equal(60.0, second.CpuPercent);
        Assert.Equal((16384, 4096, 7200), (first.MemTotalMb, first.MemAvailableMb, first.UptimeSeconds));
        Assert.Equal("C:", first.Volumes[0].Mount);
        Assert.Null(first.TemperatureC);
        Assert.NotEqual(first.SampleId, second.SampleId);
        Assert.True(Guid.TryParse(first.SampleId, out _));
    }
}

/// <summary>
/// Leituras REAIS do Windows desta máquina (sem simulação). Servem de evidência de que os coletores funcionam
/// de fato — e de medição de custo. Executam apenas no Windows.
/// </summary>
[Trait("Category", "WindowsReal")]
public class RealWindowsCollectionTests(ITestOutputHelper output)
{
    private static readonly WmiQuery Wmi = new();

    [Fact]
    public void Real_system_probe_reads_cpu_memory_volumes_and_uptime()
    {
        var probe = new WindowsSystemProbe();

        var memory = probe.ReadMemory();
        Assert.NotNull(memory);
        Assert.InRange(memory!.Value.TotalMb, 512, 8_000_000);
        Assert.InRange(memory.Value.AvailableMb, 1, memory.Value.TotalMb);
        Assert.NotNull(probe.ReadCpuTimes());
        Assert.True(probe.UptimeSeconds() > 0);
        Assert.Contains(probe.ReadVolumes(), v => v.TotalGb > 1);
        output.WriteLine($"RAM {memory.Value.TotalMb} MB total, {memory.Value.AvailableMb} MB livre; uptime {probe.UptimeSeconds() / 3600.0:F1} h");
    }

    [Fact]
    public async Task Real_metrics_sample_is_sane_and_cheap()
    {
        var collector = new MetricsCollector(new WindowsSystemProbe(), TimeProvider.System);

        var sw = System.Diagnostics.Stopwatch.StartNew();
        var first = await collector.CollectAsync(default);
        var first_ms = sw.ElapsedMilliseconds;
        sw.Restart();
        await Task.Delay(300);
        var second = await collector.CollectAsync(default);

        Assert.InRange(first.CpuPercent ?? 0, 0, 100);
        Assert.InRange(second.CpuPercent ?? 0, 0, 100);
        Assert.NotEmpty(second.Volumes);
        output.WriteLine($"amostra #1 (inclui 500 ms de priming) {first_ms} ms; CPU {first.CpuPercent}% → {second.CpuPercent}%");
        output.WriteLine(JsonSerializer.Serialize(second, Json.Options));
    }

    [Fact]
    public void Real_software_inventory_comes_from_the_registry()
    {
        var sw = System.Diagnostics.Stopwatch.StartNew();
        var result = new SoftwareCollector(new RegistryUninstallSource()).Collect(default);
        sw.Stop();
        var items = JsonSerializer.SerializeToElement(result.Data, Json.Options).GetProperty("items");

        Assert.Equal(SectionResult.Ok, result.Status);
        Assert.True(items.GetArrayLength() > 0, "esperado ao menos um software instalado");
        Assert.All(items.EnumerateArray(), i => Assert.False(string.IsNullOrWhiteSpace(i.GetProperty("name").GetString())));
        output.WriteLine($"software: {items.GetArrayLength()} itens em {sw.ElapsedMilliseconds} ms");
    }

    [Fact]
    public void Real_network_inventory_lists_interfaces_without_loopback()
    {
        var result = new NetworkCollector().Collect(default);
        var nics = JsonSerializer.SerializeToElement(result.Data, Json.Options).GetProperty("interfaces");

        Assert.Equal(SectionResult.Ok, result.Status);
        Assert.DoesNotContain(nics.EnumerateArray(), n => n.GetProperty("type").GetString() == "Loopback");
        output.WriteLine($"rede: {nics.GetArrayLength()} interfaces");
    }

    [Fact]
    public async Task Real_full_inventory_runs_every_section_within_budget()
    {
        var collectors = new ISectionCollector[]
        {
            new SystemCollector(Wmi, TimeProvider.System),
            new HardwareCollector(Wmi, new WindowsMemoryProbe(new WindowsSystemProbe())),
            new SoftwareCollector(new RegistryUninstallSource()),
            new NetworkCollector(),
            new PeripheralCollector(Wmi),
        };

        var sw = System.Diagnostics.Stopwatch.StartNew();
        var snapshot = await new InventoryCollector(collectors, TimeProvider.System).CollectAsync(default);
        sw.Stop();

        foreach (var (name, section) in snapshot.Sections)
        {
            output.WriteLine($"{name}: {section.Status}{(section.Reason is null ? "" : " (" + section.Reason + ")")}");
        }

        output.WriteLine($"inventário completo em {sw.ElapsedMilliseconds} ms; payload {Json.Serialize(snapshot).Length / 1024.0:F1} KiB");

        var system = JsonSerializer.SerializeToElement(snapshot.Sections["system"].Data, Json.Options);
        Assert.Equal("ok", snapshot.Sections["system"].Status);
        Assert.Equal(Environment.MachineName, system.GetProperty("hostname").GetString());
        Assert.Equal("ok", snapshot.Sections["hardware"].Status);
        Assert.Equal("ok", snapshot.Sections["software"].Status);
        Assert.Equal("ok", snapshot.Sections["network"].Status);
        Assert.True(sw.Elapsed < TimeSpan.FromSeconds(30), $"inventário levou {sw.Elapsed}");
        Assert.True(Json.Serialize(snapshot).Length < 4 * 1024 * 1024, "payload deve caber no limite de 4 MiB do Manager");
    }

    [Fact]
    public void Real_host_facts_report_uptime_and_addresses()
    {
        var facts = new WindowsHostFacts(Wmi, TimeProvider.System);

        Assert.True(facts.UptimeSeconds > 0);
        Assert.All(facts.GetIpAddresses(), ip => Assert.True(System.Net.IPAddress.TryParse(ip, out _)));
        output.WriteLine($"usuário interativo: {facts.GetInteractiveUser() ?? "(nenhum)"}; IPs: {string.Join(", ", facts.GetIpAddresses())}");
    }
}
