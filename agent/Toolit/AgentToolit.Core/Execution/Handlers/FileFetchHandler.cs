using System.Security.Cryptography;
using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Transfers;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// file.fetch: lê um arquivo local e o envia ao Manager. Origem validada contra TransferRoots ∪ TransferFetchOnlyRoots
/// (qualquer extensão), aberta só por <see cref="PathGuard.OpenChecked"/> (leitura, compartilhada só para leitura: em
/// uso por quem escreve → failed). <c>begin(size)</c> (acima do limite da política → rejected), chunks de
/// <c>chunk_size</c> com SHA-256, pulando os que o Manager já tem (mesmo tamanho), SHA-256 total incremental sobre
/// TODOS os bytes, e <c>complete</c>.
/// </summary>
public sealed class FileFetchHandler(
    ITransferApi api, AgentSettings settings, TimeProvider time, AgentRuntime runtime, TransferRootRegistry roots, ILogger<FileFetchHandler> logger)
    : TransferHandlerBase(api, settings, time, runtime, roots, logger)
{
    public override string Type => "file.fetch";

    protected override string Direction => TransferProtocol.Fetch;

    protected override IReadOnlyList<string> RootsFor(TransferRootRegistry registry) => registry.FetchRoots;

    protected override async Task<TaskOutcome> RunAsync(string id, TransferInfo info, IReadOnlyList<string> roots, ProgressReporter progress, CancellationToken ct)
    {
        var target = TransferPaths.Resolve(info.RemotePath, roots, blockExtensions: false, out var pathError);
        if (target is null)
        {
            Logger.LogWarning("Transferência {Id} recusada: {Reason}", id, pathError);

            return TaskOutcome.Rejected(pathError!);
        }

        var name = target.Name;
        await using var source = PathGuard.OpenChecked(target.FullPath, FileOpenIntent.ReadExisting, roots, out var code);
        if (source is null)
        {
            return OpenFailure(id, name, code, "Arquivo de origem");
        }

        var size = source.Length;
        var chunkSize = info.ChunkSize;
        if (ChunkCount(size, chunkSize) is not { } chunks)
        {
            return TaskOutcome.Rejected("Arquivo grande demais para o tamanho de chunk informado.");
        }
        Logger.LogInformation("Transferência {Id}: enviando {Name} ao Manager ({Size} bytes).", id, name, size);

        try
        {
            await Api.BeginAsync(id, size, ct);
        }
        catch (ManagerRejectedException ex) when (ex.Status == 422 && ex.Code == "transfer.too_large")
        {
            return TaskOutcome.Rejected("O arquivo excede o limite de tamanho da política: " + ex.Message);
        }

        // Chunks já recebidos só valem se a tentativa anterior informou o MESMO tamanho (outro tamanho recomeça).
        var received = info.Size == size ? new HashSet<int>(info.ReceivedChunks) : [];
        var throttle = new Throttle(Settings.TransferBytesPerSecond, Time);
        using var hasher = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        var buffer = new byte[chunkSize];
        long done = 0;

        try
        {
            await progress.ReportAsync(0, size, ct);
            for (var n = 0; n < chunks; n++)
            {
                ct.ThrowIfCancellationRequested();
                var expected = (int)Math.Min(chunkSize, size - (long)n * chunkSize);
                var read = await source.ReadAtLeastAsync(buffer.AsMemory(0, expected), expected, throwOnEndOfStream: false, ct);
                if (read != expected)
                {
                    return TaskOutcome.Failed("O arquivo mudou de tamanho durante a transferência.");
                }
                hasher.AppendData(buffer, 0, expected);   // todos os bytes entram no hash, enviados ou não

                if (!received.Contains(n))
                {
                    var bytes = buffer.AsSpan(0, expected).ToArray();
                    await Api.PutChunkAsync(id, n, bytes, Sha256Hex(bytes), ct);
                    await throttle.WaitAsync(expected, ct);
                }

                done += expected;
                await progress.ReportAsync(done, size, ct);
            }

            if (source.Length != size || await source.ReadAsync(buffer.AsMemory(0, Math.Min(1, buffer.Length)), ct) != 0)
            {
                return TaskOutcome.Failed("O arquivo mudou de tamanho durante a transferência.");
            }
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            return TaskOutcome.Failed("Erro ao ler o arquivo de origem: " + ex.Message);
        }

        var sha = Convert.ToHexString(hasher.GetHashAndReset()).ToLowerInvariant();
        var status = await Api.CompleteAsync(id, sha, size, ct);
        if (status != TaskResultStatus.Completed)
        {
            return TaskOutcome.Failed($"O Manager não confirmou a transferência (status {status}: hash ou tamanho divergente).");
        }

        Logger.LogInformation("Transferência {Id} concluída.", id);

        return TaskOutcome.Completed($"Arquivo {name} enviado ao Manager ({size} bytes, sha256 {sha}).");
    }
}
