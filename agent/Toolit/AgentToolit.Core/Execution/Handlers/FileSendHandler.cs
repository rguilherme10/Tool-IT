using System.Security.Cryptography;
using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Transfers;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// file.send: baixa do Manager para o disco. Valida o destino (raízes de envio, PathGuard, extensão), espaço livre e
/// destino existente (mesmo SHA-256 → sucesso idempotente; diferente sem overwrite → rejected); baixa os chunks para
/// <c>.toolit-{id}.part</c> na MESMA pasta, retomando pelo tamanho do .part; confere cada chunk e o SHA-256 total
/// (calculado NO handle aberto do .part, nunca reabrindo por caminho); renomeia pelo handle (<see cref="PathGuard.RenameByHandle"/>,
/// imune a TOCTOU) e chama <c>complete</c> ANTES de devolver o resultado.
/// Todo acesso a disco passa pelo handle: abrir (<see cref="PathGuard.OpenChecked"/>), ler/hash, renomear e apagar
/// (<see cref="PathGuard.DeleteByHandle"/>). NENHUM File.Move/Delete/Copy/Replace sobre caminhos vindos do Manager.
/// </summary>
public sealed class FileSendHandler(
    ITransferApi api, AgentSettings settings, TimeProvider time, AgentRuntime runtime, TransferRootRegistry roots, IDiskSpace disk, ILogger<FileSendHandler> logger)
    : TransferHandlerBase(api, settings, time, runtime, roots, logger)
{
    /// <summary>Folga exigida além do tamanho do arquivo.</summary>
    public const long DiskHeadroomBytes = 16L * 1024 * 1024;

    public override string Type => "file.send";

    protected override string Direction => TransferProtocol.Send;

    protected override IReadOnlyList<string> RootsFor(TransferRootRegistry registry) => registry.SendRoots;

    protected override async Task<TaskOutcome> RunAsync(string id, TransferInfo info, IReadOnlyList<string> roots, ProgressReporter progress, CancellationToken ct)
    {
        if (info.Size is not { } size || size < 0 || !TransferProtocol.IsSha256Hex(info.Sha256))
        {
            return TaskOutcome.Rejected("Metadados de envio incompletos (tamanho/hash).");
        }
        var chunkSize = info.ChunkSize;
        if (ChunkCount(size, chunkSize) is not { } chunks || info.ChunksTotal != chunks)
        {
            return TaskOutcome.Rejected("Metadados de envio inconsistentes (número de chunks).");
        }

        var target = TransferPaths.Resolve(info.RemotePath, roots, blockExtensions: true, out var pathError);
        if (target is null)
        {
            Logger.LogWarning("Transferência {Id} recusada: {Reason}", id, pathError);

            return TaskOutcome.Rejected(pathError!);
        }

        var name = target.Name;
        Logger.LogInformation("Transferência {Id}: recebendo {Name} ({Size} bytes).", id, name, size);

        if (!Directory.Exists(target.Directory))
        {
            return TaskOutcome.Failed("A pasta de destino não existe neste computador.");
        }

        // Destino existente: lido só por OpenChecked (sem seguir link nem aceitar hard link).
        var replace = false;
        await using (var existing = PathGuard.OpenChecked(target.FullPath, FileOpenIntent.ReadExisting, roots, out var existingCode))
        {
            if (existing is null && existingCode != "not_found")
            {
                return OpenFailure(id, name, existingCode, "Destino");
            }
            if (existing is not null)
            {
                if (await HashAsync(existing, ct) == info.Sha256)
                {
                    await existing.DisposeAsync();
                    RemoveLeftoverPart(id, target, roots);   // sobra de tentativa anterior

                    return await CompleteAsync(id, info.Sha256!, size, $"Arquivo {name} já estava no destino ({size} bytes, sha256 {info.Sha256}).", ct);
                }
                if (!info.Overwrite)
                {
                    return TaskOutcome.Rejected("O destino já existe com conteúdo diferente e a substituição não foi permitida.");
                }
                replace = true;   // overwrite permitido E destino existente validado (pré-condição do replace no rename)
            }
        }

        var free = disk.AvailableFreeSpace(target.Directory);
        if (free is null)
        {
            return TaskOutcome.Failed("Não foi possível verificar o espaço livre em disco.");
        }
        if (free < size + DiskHeadroomBytes)
        {
            return TaskOutcome.Failed($"Disco com espaço insuficiente: são necessários {size + DiskHeadroomBytes} bytes, há {free}.");
        }

        return await DownloadAndMoveAsync(id, info, target, size, chunkSize, chunks, replace, roots, progress, ct);
    }

    private async Task<TaskOutcome> DownloadAndMoveAsync(
        string id, TransferInfo info, TransferTarget target, long size, int chunkSize, int chunks, bool replace,
        IReadOnlyList<string> roots, ProgressReporter progress, CancellationToken ct)
    {
        var partPath = PathGuard.Join(target.Directory, PartName(id));

        // Abre (retoma) ou cria o .part. O handle fica aberto até o fim: hash, rename e delete são todos por ele.
        var part = PathGuard.OpenChecked(partPath, FileOpenIntent.AppendExisting, roots, out var code);
        if (part is null && code == "not_found")
        {
            part = PathGuard.OpenChecked(partPath, FileOpenIntent.CreateNew, roots, out code);
        }
        if (part is null)
        {
            return OpenFailure(id, target.Name, code, "Arquivo parcial");
        }

        var deletePart = true;   // em qualquer saída anormal o .part é apagado PELO HANDLE
        try
        {
            var offset = part.Length;
            if (offset != size && (offset % chunkSize != 0 || offset > size))
            {
                // .part que não é múltiplo do chunk (ou maior que o arquivo): descartado e recomeçado do zero.
                Logger.LogWarning("Transferência {Id}: arquivo parcial com tamanho inválido ({Length}); recomeçando.", id, offset);
                PathGuard.DeleteByHandle(part.SafeFileHandle);
                await part.DisposeAsync();
                part = PathGuard.OpenChecked(partPath, FileOpenIntent.CreateNew, roots, out code);
                if (part is null)
                {
                    deletePart = false;

                    return OpenFailure(id, target.Name, code, "Arquivo parcial");
                }
                offset = 0;
            }

            using var hasher = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
            await SeedHashAsync(part, hasher, offset, ct);   // bytes já no .part entram no hash
            part.Seek(0, SeekOrigin.End);

            var throttle = new Throttle(Settings.TransferBytesPerSecond, Time);
            var done = offset;
            await progress.ReportAsync(done, size, ct);

            // Finding 2: um .part já completo (offset == size) vai direto para hash + rename; não rebaixa o último chunk.
            for (var n = offset == size ? chunks : (int)(offset / chunkSize); n < chunks; n++)
            {
                ct.ThrowIfCancellationRequested();
                var (bytes, chunkSha) = await Api.GetChunkAsync(id, n, ct);
                var expected = (int)Math.Min(chunkSize, size - (long)n * chunkSize);
                if (bytes.Length != expected)
                {
                    return TaskOutcome.Failed($"O chunk {n} veio com tamanho errado ({bytes.Length} bytes, esperado {expected}).");
                }
                if (!string.Equals(Sha256Hex(bytes), chunkSha, StringComparison.Ordinal))
                {
                    return TaskOutcome.Failed($"O hash do chunk {n} não confere.");
                }

                ct.ThrowIfCancellationRequested();   // nunca grava depois de cancelado
                await part.WriteAsync(bytes, ct);
                await part.FlushAsync(ct);
                hasher.AppendData(bytes);
                done += expected;
                await throttle.WaitAsync(expected, ct);
                await progress.ReportAsync(done, size, ct);
            }

            if (part.Length != size)
            {
                return TaskOutcome.Failed("Tamanho do arquivo recebido diverge do informado pelo Manager.");
            }
            var sha = Convert.ToHexString(hasher.GetHashAndReset()).ToLowerInvariant();
            if (sha != info.Sha256)
            {
                Logger.LogWarning("Transferência {Id}: hash divergente em {Name}; arquivo parcial descartado.", id, target.Name);

                return TaskOutcome.Failed("Hash divergente: o hash SHA-256 do arquivo recebido não confere com o do Manager.");
            }

            // Renomeia PELO HANDLE (dir conferido, sem reparse, dentro da raiz); replace só com overwrite + destino validado.
            var renameError = PathGuard.RenameByHandle(part.SafeFileHandle, target.Directory, target.Name, replace, roots);
            if (renameError is not null)
            {
                Logger.LogWarning("Transferência {Id}: rename de {Name} recusado ({Code}).", id, target.Name, renameError);

                return TransferPaths.IsSuspicious(renameError)
                    ? TaskOutcome.Rejected(PathGuard.Message(renameError))
                    : TaskOutcome.Failed("Não foi possível concluir a gravação do destino: " + PathGuard.Message(renameError));
            }

            deletePart = false;   // o .part virou o arquivo final

            return await CompleteAsync(id, sha, size, $"Arquivo {target.Name} recebido ({size} bytes, sha256 {sha}).", ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;   // cancelamento real: apaga o .part (deletePart) e a base traduz para cancelled
        }
        catch (OperationCanceledException)
        {
            deletePart = false;   // timeout de HttpClient (não é ct): mantém o .part para retomar; o runner falha a tentativa
            throw;
        }
        catch (ManagerRejectedException)
        {
            throw;   // 409 → cancelled; 404/outros → failed (base traduz). .part apagado.
        }
        catch (ManagerUnavailableException)
        {
            deletePart = false;   // rede/5xx: mantém o .part para a próxima tentativa idempotente
            throw;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            return TaskOutcome.Failed("Erro ao gravar o arquivo parcial: " + ex.Message);
        }
        finally
        {
            if (part is not null)
            {
                if (deletePart)
                {
                    PathGuard.DeleteByHandle(part.SafeFileHandle);
                }
                await part.DisposeAsync();
            }
        }
    }

    /// <summary>Alimenta o hash com os primeiros <paramref name="count"/> bytes do .part (retomada), pelo handle aberto.</summary>
    private static async Task SeedHashAsync(FileStream part, IncrementalHash hasher, long count, CancellationToken ct)
    {
        if (count <= 0)
        {
            return;
        }
        part.Seek(0, SeekOrigin.Begin);
        var buffer = new byte[1 << 20];
        long read = 0;
        while (read < count)
        {
            var want = (int)Math.Min(buffer.Length, count - read);
            var got = await part.ReadAsync(buffer.AsMemory(0, want), ct);
            if (got <= 0)
            {
                throw new IOException("Arquivo parcial encurtou durante a leitura.");
            }
            hasher.AppendData(buffer, 0, got);
            read += got;
        }
    }

    /// <summary>Remove um .part remanescente (de tentativa anterior) PELO HANDLE; inexistente não é erro; nunca por caminho.</summary>
    private void RemoveLeftoverPart(string id, TransferTarget target, IReadOnlyList<string> roots)
    {
        var partPath = PathGuard.Join(target.Directory, PartName(id));
        var part = PathGuard.OpenChecked(partPath, FileOpenIntent.AppendExisting, roots, out var code);
        if (part is null)
        {
            if (code is not ("not_found" or "normalization_unavailable") && TransferPaths.IsSuspicious(code))
            {
                Logger.LogWarning("Transferência {Id}: arquivo parcial remanescente não removido ({Code}).", id, code);
            }

            return;
        }
        using (part)
        {
            PathGuard.DeleteByHandle(part.SafeFileHandle);
        }
    }

    private static string PartName(string id) => ".toolit-" + id + ".part";

    private async Task<TaskOutcome> CompleteAsync(string id, string sha, long size, string stdout, CancellationToken ct)
    {
        var status = await Api.CompleteAsync(id, sha, size, ct);
        if (status == TaskResultStatus.Completed)
        {
            Logger.LogInformation("Transferência {Id} concluída.", id);

            return TaskOutcome.Completed(stdout);
        }

        return TaskOutcome.Failed($"O Manager não confirmou a transferência (status {status}).");
    }

    private static async Task<string> HashAsync(Stream stream, CancellationToken ct)
    {
        stream.Position = 0;

        return Convert.ToHexString(await SHA256.HashDataAsync(stream, ct)).ToLowerInvariant();
    }
}
