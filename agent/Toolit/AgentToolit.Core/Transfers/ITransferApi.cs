namespace AgentToolit.Core.Transfers;

/// <summary>
/// Rotas assinadas de transferência do Manager (<c>/api/agent/v1/transfers/...</c>). Implementada por
/// <see cref="Net.ManagerClient"/>. NÃO repete pedidos por conta própria (rede/5xx sobem como
/// <see cref="Net.ManagerUnavailableException"/>): quem decide repetir é o handler. 4xx sobem como
/// <see cref="Net.ManagerRejectedException"/> com o código do Manager (404 request.not_found, 409 transfer.conflict,
/// 422 transfer.chunk_hash / transfer.too_large / transfer.invalid, 413, 415).
/// </summary>
public interface ITransferApi
{
    /// <summary>Metadados da transferência (caminho, direção, tamanhos, chunks já recebidos).</summary>
    Task<TransferInfo> GetAsync(string id, CancellationToken ct);

    /// <summary>Envio: bytes do chunk <paramref name="n"/> e o hash informado em X-Chunk-Sha256 (já conferido contra os bytes).</summary>
    Task<(byte[] Bytes, string Sha256)> GetChunkAsync(string id, int n, CancellationToken ct);

    /// <summary>Recuperação: grava o chunk <paramref name="n"/> (corpo bruto + X-Chunk-Sha256 em hex minúsculo).</summary>
    Task PutChunkAsync(string id, int n, byte[] bytes, string sha256, CancellationToken ct);

    /// <summary>Recuperação: informa o tamanho do arquivo (422 transfer.too_large acima do limite da política).</summary>
    Task BeginAsync(string id, long size, CancellationToken ct);

    /// <summary>Conclui; devolve o status final ("completed" ou "failed" quando hash/tamanho divergem).</summary>
    Task<string> CompleteAsync(string id, string sha256, long size, CancellationToken ct);
}
