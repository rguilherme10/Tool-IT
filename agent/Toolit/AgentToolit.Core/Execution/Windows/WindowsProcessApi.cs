using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;

namespace AgentToolit.Core.Execution.Windows;

/// <summary>
/// Processos via <see cref="Process"/>. O caminho do executável vem de QueryFullProcessImageName (acesso
/// "limited", funciona para a maioria dos processos e não carrega módulos); usuário não é coletado (nulo).
/// </summary>
public sealed class WindowsProcessApi : IProcessApi
{
    private const double Mb = 1024d * 1024d;

    public IReadOnlyList<ProcessInfo> List()
    {
        var result = new List<ProcessInfo>();
        foreach (var process in Process.GetProcesses())
        {
            using (process)
            {
                if (Describe(process) is { } info)
                {
                    result.Add(info);
                }
            }
        }

        return result;
    }

    public ProcessInfo? Get(int pid)
    {
        try
        {
            using var process = Process.GetProcessById(pid);

            return Describe(process);
        }
        catch (ArgumentException)
        {
            return null;   // não existe
        }
        catch (InvalidOperationException)
        {
            return null;   // terminou durante a consulta
        }
    }

    public bool? IsCritical(int pid)
    {
        if (pid <= 4)
        {
            return true;   // Idle/System
        }

        var handle = OpenProcess(ProcessQueryLimitedInformation, false, (uint)pid);
        if (handle == IntPtr.Zero)
        {
            return null;
        }

        try
        {
            return IsProcessCritical(handle, out var critical) ? critical : null;
        }
        finally
        {
            CloseHandle(handle);
        }
    }

    public void Kill(int pid)
    {
        using var process = Process.GetProcessById(pid);   // ArgumentException se já não existe
        process.Kill(entireProcessTree: false);
        process.WaitForExit(5000);
    }

    private static ProcessInfo? Describe(Process process)
    {
        try
        {
            var pid = process.Id;
            var name = process.ProcessName;
            double? cpu = Try(() => process.TotalProcessorTime.TotalSeconds);
            DateTimeOffset? started = Try<DateTimeOffset?>(() => new DateTimeOffset(process.StartTime.ToUniversalTime(), TimeSpan.Zero));

            return new ProcessInfo(pid, name, ImagePath(pid), null, cpu, process.WorkingSet64 / Mb, started);
        }
        catch (InvalidOperationException)
        {
            return null;   // terminou enquanto era lido
        }
    }

    private static T? Try<T>(Func<T> read)
    {
        try
        {
            return read();
        }
        catch (Exception ex) when (ex is Win32Exception or InvalidOperationException or NotSupportedException)
        {
            return default;   // acesso negado (processos protegidos/de outra sessão)
        }
    }

    private static double? Try(Func<double> read)
    {
        try
        {
            return read();
        }
        catch (Exception ex) when (ex is Win32Exception or InvalidOperationException or NotSupportedException)
        {
            return null;
        }
    }

    private const uint ProcessQueryLimitedInformation = 0x1000;

    private static string? ImagePath(int pid)
    {
        if (pid <= 4)
        {
            return null;
        }

        var handle = OpenProcess(ProcessQueryLimitedInformation, false, (uint)pid);
        if (handle == IntPtr.Zero)
        {
            return null;
        }

        try
        {
            var buffer = new StringBuilder(1024);
            var size = buffer.Capacity;

            return QueryFullProcessImageName(handle, 0, buffer, ref size) ? buffer.ToString(0, size) : null;
        }
        finally
        {
            CloseHandle(handle);
        }
    }

    // DllImport clássico (o projeto não habilita unsafe, exigido pelo gerador do LibraryImport).
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr OpenProcess(uint access, bool inherit, uint pid);

    [DllImport("kernel32.dll", EntryPoint = "QueryFullProcessImageNameW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool QueryFullProcessImageName(IntPtr process, uint flags, StringBuilder buffer, ref int size);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr handle);

    // Windows 8.1+.
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool IsProcessCritical(IntPtr process, out bool critical);
}
