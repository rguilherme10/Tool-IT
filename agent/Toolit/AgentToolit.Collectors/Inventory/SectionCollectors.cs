using System.Runtime.InteropServices;
using AgentToolit.Collectors.Metrics;
using AgentToolit.Collectors.Wmi;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Collectors.Inventory;

public interface ISectionCollector
{
    string Name { get; }

    SectionResult Collect(CancellationToken ct);
}

internal static class Queries
{
    public static readonly TimeSpan Timeout = TimeSpan.FromSeconds(10);
    public const string Cimv2 = @"root\cimv2";
}

/// <summary>Hostname, fabricante/modelo/serial, SO, boot, domínio. Três consultas WMI pequenas.</summary>
public sealed class SystemCollector(IWmiQuery wmi, TimeProvider time) : ISectionCollector
{
    public string Name => "system";

    public SectionResult Collect(CancellationToken ct)
    {
        var computer = wmi.Query(Queries.Cimv2, "SELECT Manufacturer, Model, Domain, PartOfDomain FROM Win32_ComputerSystem", Queries.Timeout).FirstOrDefault();
        var os = wmi.Query(Queries.Cimv2, "SELECT Caption, Version, BuildNumber FROM Win32_OperatingSystem", Queries.Timeout).FirstOrDefault();
        var bios = Try(() => wmi.Query(Queries.Cimv2, "SELECT SerialNumber FROM Win32_BIOS", Queries.Timeout).FirstOrDefault());

        if (os is null)
        {
            return SectionResult.NotAvailable("os_info_not_returned");
        }

        var caption = os.Str("Caption")?.Replace("Microsoft ", "", StringComparison.OrdinalIgnoreCase);
        var bootTime = time.GetUtcNow().AddMilliseconds(-Environment.TickCount64);

        return SectionResult.Success(new
        {
            hostname = Environment.MachineName,
            manufacturer = computer?.Str("Manufacturer"),
            model = computer?.Str("Model"),
            serial = SerialFilter.Clean(bios?.Str("SerialNumber")),
            os = new { name = caption, version = os.Str("Version"), build = os.Str("BuildNumber"), arch = OsArch() },
            boot_time = new DateTimeOffset(bootTime.Year, bootTime.Month, bootTime.Day, bootTime.Hour, bootTime.Minute, bootTime.Second, TimeSpan.Zero),
            domain = computer?.Bool("PartOfDomain") == true ? computer.Str("Domain") : null,
            workgroup = computer?.Bool("PartOfDomain") == false ? computer.Str("Domain") : null,
        });
    }

    public static string OsArch() => RuntimeInformation.OSArchitecture switch
    {
        Architecture.X64 => "x64",
        Architecture.Arm64 => "arm64",
        Architecture.X86 => "x86",
        _ => RuntimeInformation.OSArchitecture.ToString().ToLowerInvariant(),
    };

    private static T? Try<T>(Func<T> action)
    {
        try
        {
            return action();
        }
        catch (Exception ex) when (ex is System.Management.ManagementException or UnauthorizedAccessException or COMException)
        {
            return default;
        }
    }
}

/// <summary>Seriais "To Be Filled By O.E.M." e similares são ruído: tratados como ausentes.</summary>
public static class SerialFilter
{
    private static readonly string[] Placeholders =
        ["to be filled by o.e.m.", "default string", "system serial number", "none", "n/a", "0", "unknown", "not specified"];

    public static string? Clean(string? value) =>
        value is null || Placeholders.Contains(value.Trim().ToLowerInvariant()) ? null : value.Trim();
}

/// <summary>
/// Hardware. Cada componente é consultado isoladamente: o que falhar vai para <c>not_detected</c> com o motivo,
/// diferenciando "não existe" de "não pôde ser consultado".
/// </summary>
public sealed class HardwareCollector(IWmiQuery wmi, ISystemProbeMemory memory) : ISectionCollector
{
    public string Name => "hardware";

    public SectionResult Collect(CancellationToken ct)
    {
        var notDetected = new List<object>();

        List<object>? Component<T>(string name, Func<T> read, Func<T, List<object>> map)
        {
            try
            {
                return map(read());
            }
            catch (Exception ex) when (ex is System.Management.ManagementException or UnauthorizedAccessException or COMException or TimeoutException)
            {
                notDetected.Add(new { component = name, reason = ex is UnauthorizedAccessException ? "access_denied" : "query_failed" });

                return null;
            }
        }

        IReadOnlyList<IReadOnlyDictionary<string, object?>> Q(string wql) => wmi.Query(Queries.Cimv2, wql, Queries.Timeout);

        var cpus = Component("cpus", () => Q("SELECT Name, NumberOfCores, NumberOfLogicalProcessors, MaxClockSpeed FROM Win32_Processor"),
            rows => rows.Select(r => (object)new { name = r.Str("Name"), cores = r.Long("NumberOfCores"), threads = r.Long("NumberOfLogicalProcessors"), max_mhz = r.Long("MaxClockSpeed") }).ToList());
        var disks = Component("disks", () => Q("SELECT Model, SerialNumber, Size, InterfaceType, MediaType FROM Win32_DiskDrive"),
            rows => rows.Select(r => (object)new { model = r.Str("Model"), serial = SerialFilter.Clean(r.Str("SerialNumber")), size_gb = r.Long("Size") is { } s ? Math.Round(s / 1073741824d, 1) : (double?)null, interface_type = r.Str("InterfaceType"), media_type = r.Str("MediaType") }).ToList());
        var gpus = Component("gpus", () => Q("SELECT Name, DriverVersion, AdapterRAM FROM Win32_VideoController"),
            rows => rows.Select(r => (object)new { name = r.Str("Name"), driver_version = r.Str("DriverVersion"), vram_mb = r.Long("AdapterRAM") is { } v and > 0 ? v / 1048576 : (long?)null }).ToList());
        var board = Component("baseboard", () => Q("SELECT Manufacturer, Product, SerialNumber FROM Win32_BaseBoard"),
            rows => rows.Select(r => (object)new { manufacturer = r.Str("Manufacturer"), product = r.Str("Product"), serial = SerialFilter.Clean(r.Str("SerialNumber")) }).ToList());
        var bios = Component("bios", () => Q("SELECT Manufacturer, SMBIOSBIOSVersion, ReleaseDate FROM Win32_BIOS"),
            rows => rows.Select(r => (object)new { vendor = r.Str("Manufacturer"), version = r.Str("SMBIOSBIOSVersion"), release_date = ParseCimDate(r.Str("ReleaseDate")) }).ToList());

        var mem = memory.ReadMemoryTotalMb();
        var volumes = FixedVolumes.Read().Select(v => new { mount = v.Mount, label = v.Label, file_system = v.FileSystem, total_gb = v.TotalGb, free_gb = v.FreeGb, used_percent = v.TotalGb > 0 ? Math.Round((1 - (v.FreeGb / v.TotalGb)) * 100, 1) : (double?)null }).ToList();
        if (mem is null)
        {
            notDetected.Add(new { component = "memory", reason = "query_failed" });
        }

        return SectionResult.Success(new
        {
            cpus,
            memory_total_mb = mem,
            disks,
            volumes,
            gpus,
            baseboard = board?.FirstOrDefault(),
            bios = bios?.FirstOrDefault(),
            not_detected = notDetected,
        });
    }

    /// <summary>CIM datetime "20240131000000.000000+000" → "2024-01-31".</summary>
    public static string? ParseCimDate(string? value) =>
        value is { Length: >= 8 } && DateTime.TryParseExact(value[..8], "yyyyMMdd", null, System.Globalization.DateTimeStyles.None, out var d)
            ? d.ToString("yyyy-MM-dd") : null;
}

public interface ISystemProbeMemory
{
    long? ReadMemoryTotalMb();
}

public sealed class WindowsMemoryProbe(Metrics.ISystemProbe probe) : ISystemProbeMemory
{
    public long? ReadMemoryTotalMb() => probe.ReadMemory()?.TotalMb;
}
