using System.Runtime.InteropServices;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Collectors.Metrics;

public readonly record struct CpuTimes(ulong Idle, ulong Kernel, ulong User);

public readonly record struct MemoryInfo(long TotalMb, long AvailableMb);

public interface ISystemProbe
{
    CpuTimes? ReadCpuTimes();

    MemoryInfo? ReadMemory();

    IReadOnlyList<VolumeMetric> ReadVolumes();

    long UptimeSeconds();
}

public sealed class WindowsSystemProbe : ISystemProbe
{
    public CpuTimes? ReadCpuTimes() =>
        Native.GetSystemTimes(out var idle, out var kernel, out var user) ? new CpuTimes(idle.Value, kernel.Value, user.Value) : null;

    public MemoryInfo? ReadMemory()
    {
        var status = new Native.MemoryStatusEx { Length = (uint)Marshal.SizeOf<Native.MemoryStatusEx>() };

        return Native.GlobalMemoryStatusEx(ref status)
            ? new MemoryInfo((long)(status.TotalPhys / (1024 * 1024)), (long)(status.AvailPhys / (1024 * 1024)))
            : null;
    }

    public IReadOnlyList<VolumeMetric> ReadVolumes() => FixedVolumes.Read()
        .Select(v => new VolumeMetric(v.Mount, v.TotalGb, v.FreeGb)).ToList();

    public long UptimeSeconds() => Environment.TickCount64 / 1000;
}

internal static class FixedVolumes
{
    public sealed record Volume(string Mount, string? Label, string? FileSystem, double TotalGb, double FreeGb);

    /// <summary>Somente discos fixos prontos: ignora removíveis, ópticos e unidades de rede (podem bloquear).</summary>
    public static IReadOnlyList<Volume> Read()
    {
        var list = new List<Volume>();
        foreach (var drive in DriveInfo.GetDrives())
        {
            try
            {
                if (drive.DriveType != DriveType.Fixed || !drive.IsReady)
                {
                    continue;
                }

                list.Add(new Volume(
                    drive.Name.TrimEnd('\\'),
                    string.IsNullOrWhiteSpace(drive.VolumeLabel) ? null : drive.VolumeLabel,
                    drive.DriveFormat,
                    Math.Round(drive.TotalSize / 1073741824d, 2),
                    Math.Round(drive.AvailableFreeSpace / 1073741824d, 2)));
            }
            catch (IOException)
            {
                // Volume que sumiu entre a listagem e a leitura.
            }
            catch (UnauthorizedAccessException)
            {
            }
        }

        return list;
    }
}

/// <summary>
/// Coleta leve de saúde (CPU, memória, volumes, uptime). Sem WMI e sem contadores de desempenho localizados:
/// CPU vem de GetSystemTimes (delta entre leituras). Temperatura não é coletada (sem fonte confiável).
/// </summary>
public sealed class MetricsCollector(ISystemProbe probe, TimeProvider time)
{
    private CpuTimes? _previous;

    public async Task<MetricSample> CollectAsync(CancellationToken ct)
    {
        if (_previous is null)
        {
            _previous = probe.ReadCpuTimes();
            await Task.Delay(TimeSpan.FromMilliseconds(500), ct);
        }

        var current = probe.ReadCpuTimes();
        var cpu = CpuPercent(_previous, current);
        _previous = current ?? _previous;

        var memory = probe.ReadMemory();

        return new MetricSample(
            SampleId: Guid.NewGuid().ToString(),
            At: time.GetUtcNow(),
            CpuPercent: cpu,
            MemTotalMb: memory?.TotalMb ?? 0,
            MemAvailableMb: memory?.AvailableMb ?? 0,
            UptimeSeconds: probe.UptimeSeconds(),
            Volumes: probe.ReadVolumes(),
            TemperatureC: null);
    }

    /// <summary>Kernel inclui o tempo ocioso: uso = 1 − ocioso / (kernel + usuário).</summary>
    public static double? CpuPercent(CpuTimes? previous, CpuTimes? current)
    {
        if (previous is not { } p || current is not { } c)
        {
            return null;
        }

        var total = (c.Kernel - p.Kernel) + (c.User - p.User);
        if (total == 0 || c.Kernel < p.Kernel || c.User < p.User)
        {
            return null;
        }

        var idle = c.Idle - p.Idle;

        return Math.Round(Math.Clamp((1d - ((double)idle / total)) * 100d, 0d, 100d), 1);
    }
}
