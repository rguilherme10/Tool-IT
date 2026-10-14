using System.Security.Cryptography;
using AgentToolit.Core.Config;

namespace AgentToolit.Core.Identity;

/// <summary>
/// Identidade criptográfica da instalação: par ECDSA P-256. A chave privada nunca sai da máquina e
/// é gravada protegida por DPAPI (escopo máquina) em identity.key (ADR-004). Só a chave pública vai ao Manager.
/// </summary>
public sealed class AgentIdentity : IDisposable
{
    // Entropia fixa do produto: dificulta desproteção por processo alheio que não conheça o formato (defesa em profundidade;
    // a barreira real é a ACL do diretório).
    private static readonly byte[] Entropy = "Toolit.Agent.Identity.v1"u8.ToArray();

    private ECDsa _key;

    private AgentIdentity(ECDsa key) => _key = key;

    public ECDsa Key => _key;

    /// <summary>SubjectPublicKeyInfo DER em Base64 (o que o Manager valida como P-256).</summary>
    public string PublicKeyBase64 => Convert.ToBase64String(_key.ExportSubjectPublicKeyInfo());

    public static AgentIdentity Generate() => new(ECDsa.Create(ECCurve.NamedCurves.nistP256));

    public static AgentIdentity? TryLoad(AgentPaths paths)
    {
        if (!File.Exists(paths.IdentityFile))
        {
            return null;
        }

        var pkcs8 = ProtectedData.Unprotect(File.ReadAllBytes(paths.IdentityFile), Entropy, DataProtectionScope.LocalMachine);
        try
        {
            var key = ECDsa.Create();
            key.ImportPkcs8PrivateKey(pkcs8, out _);

            return new AgentIdentity(key);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(pkcs8);
        }
    }

    public void Save(AgentPaths paths)
    {
        var pkcs8 = _key.ExportPkcs8PrivateKey();
        try
        {
            var protectedBytes = ProtectedData.Protect(pkcs8, Entropy, DataProtectionScope.LocalMachine);
            var temp = paths.IdentityFile + ".tmp";
            File.WriteAllBytes(temp, protectedBytes);
            File.Move(temp, paths.IdentityFile, overwrite: true);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(pkcs8);
        }
    }

    /// <summary>Troca a chave em memória após o Manager confirmar a rotação (o chamador persiste com <see cref="Save"/>).</summary>
    public void Replace(AgentIdentity newIdentity)
    {
        var old = _key;
        _key = newIdentity._key;
        old.Dispose();
    }

    public void Dispose() => _key.Dispose();
}
