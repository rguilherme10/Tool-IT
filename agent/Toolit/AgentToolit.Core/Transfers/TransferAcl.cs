using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;

namespace AgentToolit.Core.Transfers;

/// <summary>
/// ACL de diretórios de transferência SEMPRE por HANDLE, nunca por caminho (<c>SetAccessControl</c> por caminho segue
/// uma junction plantada no meio e deixaria o SYSTEM reescrever a ACL de uma árvore arbitrária). O handle é aberto com
/// FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT (vê o próprio link) + SQOS anônimo; recusa reparse point;
/// lê/grava o descritor de segurança com Get/SetKernelObjectSecurity e o interpreta com <see cref="RawSecurityDescriptor"/>.
/// </summary>
public static class TransferAcl
{
    private const uint ReadControl = 0x00020000;
    private const uint WriteDac = 0x00040000;
    private const uint WriteOwner = 0x00080000;
    private const uint FileFlagBackupSemantics = 0x02000000;
    private const uint FileFlagOpenReparsePoint = 0x00200000;
    private const uint SecuritySqosPresent = 0x00100000;
    private const uint OpenExisting = 3;
    private const uint FileAttributeReparsePoint = 0x400;

    private const int OwnerSecurityInformation = 0x1;
    private const int GroupSecurityInformation = 0x2;
    private const int DaclSecurityInformation = 0x4;
    private const int ProtectedDaclSecurityInformation = unchecked((int)0x80000000);

    /// <summary>Direitos que um principal "não confiável" NÃO pode ter numa raiz: escrita/criação/remoção/troca de dono/ACL.</summary>
    private const int DangerousRights =
        0x0002 /* FILE_WRITE_DATA / FILE_ADD_FILE */ | 0x0004 /* FILE_APPEND_DATA / FILE_ADD_SUBDIRECTORY */ |
        0x0040 /* FILE_DELETE_CHILD */ | 0x00010000 /* DELETE */ | 0x00040000 /* WRITE_DAC */ | 0x00080000 /* WRITE_OWNER */ |
        0x40000000 /* GENERIC_WRITE */ | 0x10000000 /* GENERIC_ALL */;

    private static readonly string TrustedInstaller =
        "S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464";

    private static SecurityIdentifier Sid(WellKnownSidType type) => new(type, null);

    /// <summary>Dono aceitável de uma raiz (ninguém sem privilégio).</summary>
    private static bool IsTrustedOwner(SecurityIdentifier? sid) =>
        sid is not null && (sid == Sid(WellKnownSidType.LocalSystemSid) || sid == Sid(WellKnownSidType.BuiltinAdministratorsSid)
            || sid.Value == TrustedInstaller);

    /// <summary>Principal que pode ter direitos de escrita sem tornar a raiz insegura.</summary>
    private static bool IsTrustedPrincipal(SecurityIdentifier sid) =>
        sid == Sid(WellKnownSidType.LocalSystemSid) || sid == Sid(WellKnownSidType.BuiltinAdministratorsSid)
            || sid.Value == TrustedInstaller;

    /// <summary>Abre um handle de diretório que vê o próprio link (não segue reparse). Null = não abriu.</summary>
    public static SafeFileHandle? OpenDirectory(string dir, bool forWrite, out string? error)
    {
        var access = ReadControl | (forWrite ? WriteDac | WriteOwner : 0u);
        var handle = CreateFileW(dir, access, 0x7 /* share r/w/d */, IntPtr.Zero, OpenExisting,
            FileFlagBackupSemantics | FileFlagOpenReparsePoint | SecuritySqosPresent /* anônimo */, IntPtr.Zero);
        if (handle.IsInvalid)
        {
            var win32 = Marshal.GetLastWin32Error();
            handle.Dispose();
            error = win32 == 2 || win32 == 3 ? "not_found" : "open_failed";

            return null;
        }
        if (!GetFileInformationByHandle(handle, out var info))
        {
            handle.Dispose();
            error = "disk_check_failed";

            return null;
        }
        if ((info.FileAttributes & FileAttributeReparsePoint) != 0)
        {
            handle.Dispose();
            error = "reparse_point";

            return null;
        }

        error = null;

        return handle;
    }

    /// <summary>Aplica, PELO HANDLE, a DACL protegida (sem herança; só SYSTEM e Administradores) e o dono Administradores.</summary>
    public static bool ApplyRestricted(SafeFileHandle dirHandle)
    {
        var dacl = new DiscretionaryAcl(isContainer: true, isDS: false, capacity: 2);
        foreach (var sid in new[] { WellKnownSidType.LocalSystemSid, WellKnownSidType.BuiltinAdministratorsSid })
        {
            dacl.AddAccess(AccessControlType.Allow, Sid(sid), unchecked((int)0x10000000) /* GENERIC_ALL */,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None);
        }

        var sd = new CommonSecurityDescriptor(isContainer: true, isDS: false,
            ControlFlags.DiscretionaryAclPresent | ControlFlags.DiscretionaryAclProtected,
            owner: Sid(WellKnownSidType.BuiltinAdministratorsSid), group: null, systemAcl: null, discretionaryAcl: dacl);

        var binary = new byte[sd.BinaryLength];
        sd.GetBinaryForm(binary, 0);

        return SetKernelObjectSecurity(dirHandle,
            OwnerSecurityInformation | DaclSecurityInformation | ProtectedDaclSecurityInformation, binary);
    }

    /// <summary>
    /// Confere, PELO HANDLE, que a raiz é segura: dono confiável e a DACL não concede escrita/criação/remoção/troca de
    /// ACL a nenhum principal fora de SYSTEM, Administradores, TrustedInstaller e CREATOR OWNER (só-herança). Devolve
    /// null se segura, ou o nome/sid do principal ofensor (ou um código de erro) para registrar.
    /// </summary>
    public static string? InspectSafety(SafeFileHandle dirHandle)
    {
        if (!TryGetSecurity(dirHandle, out var sd))
        {
            return "não foi possível ler a ACL";
        }

        if (!IsTrustedOwner(sd.Owner))
        {
            return "dono não confiável (" + Describe(sd.Owner) + ")";
        }

        if (sd.DiscretionaryAcl is not { } dacl)
        {
            return "ACL ausente";   // null DACL = acesso total a todos
        }

        foreach (var ace in dacl.OfType<CommonAce>())
        {
            if (ace.AceType != AceType.AccessAllowed || (ace.AccessMask & DangerousRights) == 0)
            {
                continue;
            }
            var sid = ace.SecurityIdentifier;
            if (IsTrustedPrincipal(sid))
            {
                continue;
            }
            // CREATOR OWNER só vale se for herança pura (InheritOnly): não concede nada ao diretório em si.
            if (sid == Sid(WellKnownSidType.CreatorOwnerSid) && (ace.InheritanceFlags & InheritanceFlags.ContainerInherit) == 0
                && (ace.AceFlags & AceFlags.InheritOnly) != 0)
            {
                continue;
            }

            return Describe(sid);
        }

        return null;
    }

    private static bool TryGetSecurity(SafeFileHandle handle, out RawSecurityDescriptor sd)
    {
        sd = null!;
        var length = 0u;
        GetKernelObjectSecurity(handle, OwnerSecurityInformation | GroupSecurityInformation | DaclSecurityInformation, null, 0, ref length);
        if (length == 0)
        {
            return false;
        }
        var buffer = new byte[length];
        if (!GetKernelObjectSecurity(handle, OwnerSecurityInformation | GroupSecurityInformation | DaclSecurityInformation, buffer, length, ref length))
        {
            return false;
        }
        sd = new RawSecurityDescriptor(buffer, 0);

        return true;
    }

    private static string Describe(SecurityIdentifier? sid)
    {
        if (sid is null)
        {
            return "desconhecido";
        }
        try
        {
            return ((NTAccount)sid.Translate(typeof(NTAccount))).Value;
        }
        catch (Exception ex) when (ex is IdentityNotMappedException or SystemException)
        {
            return sid.Value;
        }
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true, ExactSpelling = true)]
    private static extern SafeFileHandle CreateFileW(string lpFileName, uint dwDesiredAccess, uint dwShareMode,
        IntPtr lpSecurityAttributes, uint dwCreationDisposition, uint dwFlagsAndAttributes, IntPtr hTemplateFile);

    [DllImport("kernel32.dll", SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetFileInformationByHandle(SafeFileHandle hFile, out ByHandleFileInformation info);

    [DllImport("advapi32.dll", SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetKernelObjectSecurity(SafeFileHandle handle, int securityInformation, byte[]? descriptor, uint length, ref uint lengthNeeded);

    [DllImport("advapi32.dll", SetLastError = true, ExactSpelling = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetKernelObjectSecurity(SafeFileHandle handle, int securityInformation, byte[] descriptor);

    [StructLayout(LayoutKind.Sequential)]
    private struct ByHandleFileInformation
    {
        public uint FileAttributes;
        public uint CreationTimeLow, CreationTimeHigh;
        public uint LastAccessTimeLow, LastAccessTimeHigh;
        public uint LastWriteTimeLow, LastWriteTimeHigh;
        public uint VolumeSerialNumber;
        public uint FileSizeHigh, FileSizeLow;
        public uint NumberOfLinks;
        public uint FileIndexHigh, FileIndexLow;
    }
}
