using System.Net.NetworkInformation;
using System.Net.Sockets;
using AgentToolit.Collectors.Wmi;
using AgentToolit.Core.Host;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Collectors.Inventory;

public sealed class NetworkCollector : ISectionCollector
{
    public string Name => "network";

    public SectionResult Collect(CancellationToken ct)
    {
        var interfaces = NetworkInterface.GetAllNetworkInterfaces()
            .Where(n => n.NetworkInterfaceType is not (NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel))
            .Select(n => new
            {
                name = n.Name,
                description = n.Description,
                type = n.NetworkInterfaceType.ToString(),
                mac = FormatMac(n.GetPhysicalAddress()),
                ips = NetworkAddresses.Unicast(n),
                up = n.OperationalStatus == OperationalStatus.Up,
                speed_mbps = n.OperationalStatus == OperationalStatus.Up && n.Speed > 0 ? n.Speed / 1_000_000 : (long?)null,
            })
            .ToList();

        return SectionResult.Success(new { interfaces });
    }

    public static string? FormatMac(PhysicalAddress address)
    {
        var bytes = address.GetAddressBytes();

        return bytes.Length == 0 ? null : string.Join(':', bytes.Select(b => b.ToString("X2")));
    }
}

public static class NetworkAddresses
{
    /// <summary>IPv4 e IPv6 globais; descarta link-local (169.254/16 e fe80::/10) e loopback.</summary>
    public static List<string> Unicast(NetworkInterface nic) => nic.GetIPProperties().UnicastAddresses
        .Select(a => a.Address)
        .Where(a => !System.Net.IPAddress.IsLoopback(a) && !(a.AddressFamily == AddressFamily.InterNetworkV6 && a.IsIPv6LinkLocal)
                    && !(a.AddressFamily == AddressFamily.InterNetwork && a.GetAddressBytes() is [169, 254, ..]))
        .Select(a => a.ToString())
        .ToList();
}

/// <summary>Monitores (WmiMonitorID) e impressoras. Cada fonte falha de forma independente.</summary>
public sealed class PeripheralCollector(IWmiQuery wmi) : ISectionCollector
{
    public string Name => "peripherals";

    public SectionResult Collect(CancellationToken ct)
    {
        var notDetected = new List<object>();
        List<object>? monitors = null, printers = null;

        try
        {
            monitors = wmi.Query(@"root\wmi", "SELECT ManufacturerName, UserFriendlyName, SerialNumberID, YearOfManufacture FROM WmiMonitorID", Queries.Timeout)
                .Select(r => (object)new
                {
                    name = DecodeChars(r, "UserFriendlyName"),
                    manufacturer = DecodeChars(r, "ManufacturerName"),
                    serial = DecodeChars(r, "SerialNumberID"),
                    year = r.Long("YearOfManufacture"),
                }).ToList();
        }
        catch (Exception ex) when (IsWmiFailure(ex))
        {
            notDetected.Add(new { component = "monitors", reason = Reason(ex) });
        }

        try
        {
            printers = wmi.Query(Queries.Cimv2, "SELECT Name, DriverName, PortName, Default, Network FROM Win32_Printer", Queries.Timeout)
                .Select(r => (object)new { name = r.Str("Name"), driver = r.Str("DriverName"), port = r.Str("PortName"), is_default = r.Bool("Default"), is_network = r.Bool("Network") }).ToList();
        }
        catch (Exception ex) when (IsWmiFailure(ex))
        {
            notDetected.Add(new { component = "printers", reason = Reason(ex) });
        }

        if (monitors is null && printers is null)
        {
            return SectionResult.NotAvailable(((dynamic)notDetected[0]).reason);
        }

        return SectionResult.Success(new { monitors, printers, not_detected = notDetected });
    }

    private static bool IsWmiFailure(Exception ex) =>
        ex is System.Management.ManagementException or UnauthorizedAccessException or System.Runtime.InteropServices.COMException or TimeoutException;

    private static string Reason(Exception ex) => ex is UnauthorizedAccessException ? "access_denied" : "query_failed";

    /// <summary>WmiMonitorID entrega strings como arrays de ushort terminados em zero.</summary>
    public static string? DecodeChars(IReadOnlyDictionary<string, object?> row, string key)
    {
        if (!row.TryGetValue(key, out var v) || v is not ushort[] chars)
        {
            return null;
        }

        var text = new string(chars.TakeWhile(c => c != 0).Select(c => (char)c).ToArray());

        return WmiValue.Clean(text);
    }
}

/// <summary>Fatos do host para o heartbeat (usuário interativo em cache de 60 s; IPs; uptime).</summary>
public sealed class WindowsHostFacts(IWmiQuery wmi, TimeProvider time) : IHostFacts
{
    private string? _user;
    private DateTimeOffset _userAt = DateTimeOffset.MinValue;

    public long UptimeSeconds => Environment.TickCount64 / 1000;

    public string? GetInteractiveUser()
    {
        if (time.GetUtcNow() - _userAt < TimeSpan.FromSeconds(60))
        {
            return _user;
        }

        try
        {
            _user = wmi.Query(@"root\cimv2", "SELECT UserName FROM Win32_ComputerSystem", TimeSpan.FromSeconds(5)).FirstOrDefault()?.Str("UserName");
        }
        catch (Exception ex) when (ex is System.Management.ManagementException or UnauthorizedAccessException or System.Runtime.InteropServices.COMException)
        {
            _user = null;
        }

        _userAt = time.GetUtcNow();

        return _user;
    }

    public IReadOnlyList<string> GetIpAddresses() => NetworkInterface.GetAllNetworkInterfaces()
        .Where(n => n.OperationalStatus == OperationalStatus.Up && n.NetworkInterfaceType is not (NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel))
        .SelectMany(NetworkAddresses.Unicast)
        .Distinct()
        .Take(32)
        .ToList();
}

/// <summary>Orquestra as seções com timeout e isolamento de falhas (uma seção ruim não derruba o inventário).</summary>
public sealed class InventoryCollector(IEnumerable<ISectionCollector> collectors, TimeProvider time, TimeSpan? sectionTimeout = null)
{
    public static readonly TimeSpan DefaultSectionTimeout = TimeSpan.FromSeconds(30);

    public async Task<InventoryRequest> CollectAsync(CancellationToken ct)
    {
        var sections = new Dictionary<string, SectionResult>();

        foreach (var collector in collectors)
        {
            ct.ThrowIfCancellationRequested();
            sections[collector.Name] = await RunAsync(collector, sectionTimeout ?? DefaultSectionTimeout, ct);
        }

        return new InventoryRequest(Guid.NewGuid().ToString(), time.GetUtcNow(), sections);
    }

    private static async Task<SectionResult> RunAsync(ISectionCollector collector, TimeSpan timeout, CancellationToken ct)
    {
        try
        {
            return await Task.Run(() => collector.Collect(ct), ct).WaitAsync(timeout, ct);
        }
        catch (TimeoutException)
        {
            return SectionResult.Failed("timeout");
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (UnauthorizedAccessException)
        {
            return SectionResult.NotAvailable("access_denied");
        }
        catch (Exception ex)
        {
            // Só o tipo da exceção: a mensagem pode conter caminhos/dados do equipamento.
            return SectionResult.Failed(ex.GetType().Name);
        }
    }
}
