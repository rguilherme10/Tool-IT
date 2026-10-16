using System.Security.Cryptography;
using AgentToolit.Core.Config;
using AgentToolit.Core.Net;
using AgentToolit.Core.Transfers;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// file.send: baixa do Manager para o disco. Valida o destino (raízes de envio, PathGuard, extensão), espaço livre e
/// destino existente (mesmo SHA-256 → sucesso idempotente; diferente sem overwrite → rejected); baixa os chunks para
/// <c>.toolit-{id}.part</c> na MESMA pasta, retomando pelo tamanho do .part; confere cada chunk e o SHA-256 total;
/// move para o nome final (sem sobrescrever, salvo overwrite) e chama <c>complete</c> ANTES de devolver o resultado.
/// Toda abertura passa por <see cref="PathGuard.OpenChecked"/>; o move e a remoção do próprio .part revalidam os
/// caminhos no disco imediatamente antes.
/// </summary>
public sealed class FileSendHandler(
    ITransferApi api, AgentSettings settings, TimeProvider time, AgentRuntime runtime, IDiskSpace disk, ILogger<FileSendHandler> logger)
    : TransferHandlerBase(api, settings, time, runtime, logger)
{
    /// <summary>Folga exigida além do tamanho do arquivo.</summary>
    public const long DiskHeadroomBytes = 16L * 1024 * 1024;

    public override string Type => "file.send";

    protected override string Direction => TransferProtocol.Send;

    protected override IReadOnlyList<string> Roots(AgentSettings s, ILogger l) => TransferRootSetup.SendRoots(s, l);

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
                var current = await HashAsync(existing, ct);
                if (current == info.Sha256)
                {
                    await existing.DisposeAsync();
                    DeletePart(id, PartPath(target, id), roots);   // sobra de tentativa anterior, se houver

                    return await CompleteAsync(id, info.Sha256!, size, $"Arquivo {name} já estava no destino ({size} bytes, sha256 {info.Sha256}).", ct);
                }
                if (!info.Overwrite)
                {
                    return TaskOutcome.Rejected("O destino já existe com conteúdo diferente e a substituição não foi permitida.");
                }
                replace = true;
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

        var partPath = PartPath(target, id);
        string? failure;
        try
        {
            failure = await DownloadAsync(id, name, partPath, size, chunkSize, chunks, roots, progress, ct);
        }
        catch (Exception ex) when (ex is OperationCanceledException or ManagerRejectedException)
        {
            DeletePart(id, partPath, roots);
            throw;   // a base traduz (cancelled / failed)
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            DeletePart(id, partPath, roots);

            return TaskOutcome.Failed("Erro ao gravar o arquivo parcial: " + ex.Message);
        }
        if (failure is not null)
        {
            return failure.StartsWith('!') ? TaskOutcome.Rejected(failure[1..]) : TaskOutcome.Failed(failure);
        }

        // SHA-256 total do .part (lido de novo pelo OpenChecked, em streaming).
        string sha;
        await using (var check = PathGuard.OpenChecked(partPath, FileOpenIntent.ReadExisting, roots, out var checkCode))
        {
            if (check is null)
            {
                return OpenFailure(id, name, checkCode, "Arquivo parcial");
            }
            if (check.Length != size)
            {
                await check.DisposeAsync();
                DeletePart(id, partPath, roots);

                return TaskOutcome.Failed("Tamanho do arquivo recebido diverge do informado pelo Manager.");
            }
            sha = await HashAsync(check, ct);
        }
        if (sha != info.Sha256)
        {
            DeletePart(id, partPath, roots);
            Logger.LogWarning("Transferência {Id}: hash divergente em {Name}; arquivo parcial descartado.", id, name);

            return TaskOutcome.Failed("Hash divergente: o hash SHA-256 do arquivo recebido não confere com o do Manager.");
        }

        if (MoveToFinal(id, partPath, target, replace, roots) is { } moveError)
        {
            DeletePart(id, partPath, roots);

            return moveError;
        }

        return await CompleteAsync(id, sha, size, $"Arquivo {name} recebido ({size} bytes, sha256 {sha}).", ct);
    }

    private static string PartPath(TransferTarget target, string id) => PathGuard.Join(target.Directory, ".toolit-" + id + ".part");

    /// <summary>
    /// Abre (retoma) ou cria o .part e baixa os chunks que faltam. Devolve null quando todos foram gravados, ou o
    /// motivo da falha ("!" na frente = rejected). Chunk inválido: o .part é apagado aqui.
    /// </summary>
    private async Task<string?> DownloadAsync(
        string id, string name, string partPath, long size, int chunkSize, int chunks, IReadOnlyList<string> roots,
        ProgressReporter progress, CancellationToken ct)
    {
        var part = PathGuard.OpenChecked(partPath, FileOpenIntent.AppendExisting, roots, out var code);
        if (part is null && code == "not_found")
        {
            part = PathGuard.OpenChecked(partPath, FileOpenIntent.CreateNew, roots, out code);
        }
        if (part is null)
        {
            var refused = OpenFailure(id, name, code, "Arquivo parcial");

            return (refused.Status == TaskResultStatus.Rejected ? "!" : "") + refused.Error;
        }

        var offset = part.Length;
        if (offset != size && (offset % chunkSize != 0 || offset > size))
        {
            // .part que não é múltiplo do chunk (ou maior que o arquivo): descartado e recomeçado do zero.
            Logger.LogWarning("Transferência {Id}: arquivo parcial com tamanho inválido ({Length}); recomeçando.", id, offset);
            await part.DisposeAsync();
            DeletePart(id, partPath, roots);
            part = PathGuard.OpenChecked(partPath, FileOpenIntent.CreateNew, roots, out code);
            if (part is null)
            {
                var refused = OpenFailure(id, name, code, "Arquivo parcial");

                return (refused.Status == TaskResultStatus.Rejected ? "!" : "") + refused.Error;
            }
            offset = 0;
        }

        string? failure = null;
        await using (part)
        {
            var throttle = new Throttle(Settings.TransferBytesPerSecond, Time);
            var done = offset;
            await progress.ReportAsync(done, size, ct);
            for (var n = (int)(offset / chunkSize); n < chunks; n++)
            {
                ct.ThrowIfCancellationRequested();
                var (bytes, chunkSha) = await Api.GetChunkAsync(id, n, ct);
                var expected = (int)Math.Min(chunkSize, size - (long)n * chunkSize);
                if (bytes.Length != expected)
                {
                    failure = $"O chunk {n} veio com tamanho errado ({bytes.Length} bytes, esperado {expected}).";
                    break;
                }
                if (!string.Equals(Sha256Hex(bytes), chunkSha, StringComparison.Ordinal))
                {
                    failure = $"O hash do chunk {n} não confere.";
                    break;
                }

                ct.ThrowIfCancellationRequested();   // nunca grava depois de cancelado
                await part.WriteAsync(bytes, ct);
                await part.FlushAsync(ct);
                done += expected;
                await throttle.WaitAsync(expected, ct);
                await progress.ReportAsync(done, size, ct);
            }
        }

        if (failure is not null)
        {
            DeletePart(id, partPath, roots);
            Logger.LogWarning("Transferência {Id}: {Failure} Arquivo parcial descartado.", id, failure);
        }

        return failure;
    }

    /// <summary>Move o .part para o nome final; revalida os dois caminhos no disco logo antes. Null = sucesso.</summary>
    private TaskOutcome? MoveToFinal(string id, string partPath, TransferTarget target, bool replace, IReadOnlyList<string> roots)
    {
        if ((PathGuard.ValidateOnDisk(partPath, roots) ?? PathGuard.ValidateOnDisk(target.FullPath, roots)) is { } reason)
        {
            Logger.LogWarning("Transferência {Id}: caminho mudou antes do move ({Reason}).", id, reason);

            return TaskOutcome.Rejected(reason);
        }

        var overwrite = false;
        if (replace)
        {
            // O destino precisa continuar sendo um arquivo comum dentro da raiz (sem link/hard link) para ser trocado.
            using var existing = PathGuard.OpenChecked(target.FullPath, FileOpenIntent.ReadExisting, roots, out var code);
            if (existing is null && code != "not_found")
            {
                return OpenFailure(id, target.Name, code, "Destino");
            }
            overwrite = existing is not null;
        }

        try
        {
            File.Move(partPath, target.FullPath, overwrite);   // troca atômica na mesma pasta; nunca deixa o final pela metade
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            return TaskOutcome.Failed(overwrite
                ? "Não foi possível substituir o destino: " + ex.Message
                : "Não foi possível criar o destino (já existe ou está em uso): " + ex.Message);
        }

        return null;
    }

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

    /// <summary>Apaga o PRÓPRIO .part, revalidando o caminho no disco imediatamente antes. Nunca lança.</summary>
    private void DeletePart(string id, string partPath, IReadOnlyList<string> roots)
    {
        if (PathGuard.ValidateOnDisk(partPath, roots) is { } reason)
        {
            Logger.LogWarning("Transferência {Id}: arquivo parcial não removido ({Reason}).", id, reason);

            return;
        }

        try
        {
            File.Delete(partPath);   // inexistente não é erro
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            Logger.LogWarning("Transferência {Id}: não foi possível remover o arquivo parcial ({Error}).", id, ex.Message);
        }
    }

    private static async Task<string> HashAsync(Stream stream, CancellationToken ct)
    {
        stream.Position = 0;

        return Convert.ToHexString(await SHA256.HashDataAsync(stream, ct)).ToLowerInvariant();
    }
}
