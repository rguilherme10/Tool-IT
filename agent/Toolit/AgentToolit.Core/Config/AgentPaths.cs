using System.Security.AccessControl;
using System.Security.Principal;

namespace AgentToolit.Core.Config;

/// <summary>
/// Diretório de dados do Agent: %ProgramData%\Toolit\Agent. Nada aqui contém segredo em texto puro;
/// a chave privada fica protegida por DPAPI (identity.key) e, em produção, o diretório é restrito a SYSTEM/Administradores.
/// </summary>
public sealed class AgentPaths(string root)
{
    public static AgentPaths Default() =>
        new(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Toolit", "Agent"));

    public string Root { get; } = root;
    public string ConfigFile => Path.Combine(Root, "config.json");
    public string StateFile => Path.Combine(Root, "state.json");
    public string IdentityFile => Path.Combine(Root, "identity.key");
    public string QueueFile => Path.Combine(Root, "queue.db");
    public string LogDirectory => Path.Combine(Root, "logs");

    /// <summary>Administrador ou SYSTEM: só quem pode restringir a ACL sem se trancar para fora do próprio diretório.</summary>
    public static bool IsElevated()
    {
        using var identity = WindowsIdentity.GetCurrent();

        return identity.IsSystem || new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
    }

    /// <summary>
    /// Cria o diretório. Com <paramref name="restrictAcl"/>, remove a herança e concede acesso apenas a SYSTEM e
    /// Administradores — mas SOMENTE quando o processo é elevado (um usuário comum se trancaria para fora).
    /// </summary>
    /// <returns>true se a ACL restrita foi aplicada.</returns>
    public bool EnsureCreated(bool restrictAcl)
    {
        Directory.CreateDirectory(Root);
        Directory.CreateDirectory(LogDirectory);

        if (!restrictAcl || !IsElevated())
        {
            return false;
        }

        new DirectoryInfo(Root).SetAccessControl(RestrictedSecurity());

        return true;
    }

    /// <summary>
    /// ACL "só SYSTEM e Administradores, controle total", sem herança do pai (protegida) e herdada pelos filhos.
    /// Usada no diretório de dados e nas raízes de transferência (impede usuário comum de plantar junction/hard link).
    /// </summary>
    public static DirectorySecurity RestrictedSecurity()
    {
        var security = new DirectorySecurity();
        security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
        foreach (var sid in new[] { WellKnownSidType.LocalSystemSid, WellKnownSidType.BuiltinAdministratorsSid })
        {
            security.AddAccessRule(new FileSystemAccessRule(
                new SecurityIdentifier(sid, null),
                FileSystemRights.FullControl,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit,
                PropagationFlags.None,
                AccessControlType.Allow));
        }

        return security;
    }

    /// <summary>Falha cedo (antes de consumir o token de enrollment) se o diretório de dados não é gravável.</summary>
    public void AssertWritable()
    {
        var probe = Path.Combine(Root, $".write-test-{Guid.NewGuid():N}");
        File.WriteAllText(probe, "x");
        File.Delete(probe);
    }
}
