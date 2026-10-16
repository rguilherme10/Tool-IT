using System.Text.Json.Serialization;

namespace AgentToolit.Core.Transfers;

/// <summary>
/// Metadados de <c>GET /transfers/{id}</c> (10 chaves; contratos tests/contracts/transfer-metadata.json e
/// transfer-metadata-fetch.json). Na recuperação (fetch), antes do <c>begin</c>, <see cref="Size"/>,
/// <see cref="Sha256"/> e <see cref="ChunksTotal"/> são nulos; <see cref="Sha256"/> é sempre nulo no fetch.
/// O Agent confere <see cref="EndpointId"/> e <see cref="Direction"/> antes de qualquer coisa.
/// </summary>
public sealed record TransferInfo(
    string TransferId,
    string EndpointId,
    string Direction,
    long? Size,
    [property: JsonPropertyName("sha256")] string? Sha256,
    int ChunkSize,
    int? ChunksTotal,
    string RemotePath,
    bool Overwrite,
    IReadOnlyList<int> ReceivedChunks);

/// <summary>Corpo de <c>POST /transfers/{id}/begin</c> (recuperação).</summary>
public sealed record TransferBeginRequest(long Size);

/// <summary>Resposta de <c>POST /transfers/{id}/begin</c>.</summary>
public sealed record TransferBeginResponse(string Status, int ChunkSize, int ChunksTotal);

/// <summary>Corpo de <c>POST /transfers/{id}/complete</c>.</summary>
public sealed record TransferCompleteRequest([property: JsonPropertyName("sha256")] string Sha256, long Size);

/// <summary>Resposta de <c>POST /transfers/{id}/complete</c>: "completed" ou "failed" (hash/tamanho divergente).</summary>
public sealed record TransferCompleteResponse(string Status);

/// <summary>Resposta de <c>PUT /transfers/{id}/chunks/{n}</c>.</summary>
public sealed record TransferChunkAck(int ChunksReceived);

/// <summary>Constantes do protocolo de transferência (docs/api/agent-api-v1.md, seção de transferências).</summary>
public static class TransferProtocol
{
    public const string Send = "send";
    public const string Fetch = "fetch";

    /// <summary>Hash SHA-256 do chunk, hex minúsculo; obrigatório na resposta do GET e no PUT.</summary>
    public const string ChunkHashHeader = "X-Chunk-Sha256";

    public const string OctetStream = "application/octet-stream";

    /// <summary>Teto do corpo de um chunk (2,5 MiB, o mesmo do Manager); o chunk normal tem 2 MiB.</summary>
    public const int MaxChunkBytes = 2_621_440;

    /// <summary>Exatamente 64 dígitos hex minúsculos.</summary>
    public static bool IsSha256Hex(string? value)
    {
        if (value is null || value.Length != 64)
        {
            return false;
        }
        foreach (var c in value)
        {
            if (c is not ((>= '0' and <= '9') or (>= 'a' and <= 'f')))
            {
                return false;
            }
        }

        return true;
    }
}
