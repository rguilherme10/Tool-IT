using System.Security.Cryptography;
using System.Text;

namespace AgentToolit.Core.Protocol;

/// <summary>
/// Assinatura de requests do protocolo v1 (docs/api/agent-api-v1.md §1, ADR-004).
/// A string canônica deve ser idêntica à do Manager; o vetor tests/contracts/signature-vector.json garante isso.
/// </summary>
public static class RequestSigner
{
    public const string VersionTag = "v1";

    public static string Canonical(string method, string path, string query, string timestamp, string nonce, ReadOnlySpan<byte> body)
    {
        // Query crua (sem decodificar), dividida em '&' e ordenada em ordem ordinal.
        string[] parts = string.IsNullOrEmpty(query) ? [] : query.Split('&');
        Array.Sort(parts, StringComparer.Ordinal);
        var bodyHash = Convert.ToHexString(SHA256.HashData(body)).ToLowerInvariant();

        return string.Join('\n', VersionTag, method.ToUpperInvariant(), path, string.Join('&', parts), timestamp, nonce, bodyHash);
    }

    /// <summary>ECDSA P-256/SHA-256, assinatura em DER (RFC 3279), Base64 — compatível com openssl_verify do PHP.</summary>
    public static string Sign(ECDsa key, string canonical) =>
        Convert.ToBase64String(key.SignData(Encoding.UTF8.GetBytes(canonical), HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence));

    public static bool Verify(ECDsa publicKey, string canonical, string signatureBase64) =>
        publicKey.VerifyData(Encoding.UTF8.GetBytes(canonical), Convert.FromBase64String(signatureBase64),
            HashAlgorithmName.SHA256, DSASignatureFormat.Rfc3279DerSequence);

    /// <summary>Um nonce novo por tentativa (inclusive em retentativas): repetir é tratado como replay.</summary>
    public static string NewNonce() =>
        Convert.ToBase64String(RandomNumberGenerator.GetBytes(18)).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}
