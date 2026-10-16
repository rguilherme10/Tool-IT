namespace AgentToolit.Core.Transfers;

/// <summary>Espaço livre da unidade de um diretório (seam de teste). Null = não deu para saber.</summary>
public interface IDiskSpace
{
    long? AvailableFreeSpace(string directory);
}

/// <summary><see cref="DriveInfo.AvailableFreeSpace"/> da unidade do caminho (cotas do usuário do processo incluídas).</summary>
public sealed class SystemDiskSpace : IDiskSpace
{
    public long? AvailableFreeSpace(string directory)
    {
        try
        {
            return new DriveInfo(Path.GetPathRoot(directory) ?? directory).AvailableFreeSpace;
        }
        catch (Exception ex) when (ex is ArgumentException or IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }
}
