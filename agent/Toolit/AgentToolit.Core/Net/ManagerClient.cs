using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Protocol;
using AgentToolit.Core.Transfers;

namespace AgentToolit.Core.Net;

/// <summary>
/// Cliente HTTPS do Manager (API v1). Toda chamada, exceto enroll/meta, é assinada com a chave do Agent.
/// Cada tentativa usa timestamp e nonce novos. Validação de certificado é a padrão do sistema — sem bypass.
/// </summary>
public sealed class ManagerClient(
    HttpClient http, AgentIdentity identity, AgentRuntime runtime, TimeProvider time, string agentVersion) : ITransferApi
{
    public const string ApiPrefix = "/api/agent/v1";

    private long _clockOffsetSeconds;

    public string AgentVersion { get; } = agentVersion;

    public Task<ServerMeta> GetMetaAsync(CancellationToken ct) =>
        SendAsync<object, ServerMeta>(HttpMethod.Get, $"{ApiPrefix}/meta", null, signed: false, ct);

    public Task<EnrollResponse> EnrollAsync(EnrollRequest request, CancellationToken ct) =>
        SendAsync<EnrollRequest, EnrollResponse>(HttpMethod.Post, $"{ApiPrefix}/enroll", request, signed: false, ct);

    public Task<HeartbeatResponse> HeartbeatAsync(HeartbeatRequest request, CancellationToken ct) =>
        PostSignedAsync<HeartbeatRequest, HeartbeatResponse>("/heartbeat", request, ct);

    public Task<MetricsAck> SendMetricsAsync(MetricsBatch batch, CancellationToken ct) =>
        PostSignedAsync<MetricsBatch, MetricsAck>("/metrics", batch, ct);

    public Task<InventoryAck> SendInventoryAsync(InventoryRequest request, CancellationToken ct) =>
        PostSignedAsync<InventoryRequest, InventoryAck>("/inventory", request, ct);

    public Task<RotateKeyResponse> RotateKeyAsync(RotateKeyRequest request, CancellationToken ct) =>
        PostSignedAsync<RotateKeyRequest, RotateKeyResponse>("/keys/rotate", request, ct);

    /// <summary>Poll curto (wait=0). <c>null</c> quando não há tarefa (204).</summary>
    public Task<TaskAssignment?> NextTaskAsync(CancellationToken ct) =>
        SendSignedAsync<object, TaskAssignment?>(HttpMethod.Get, $"{ApiPrefix}/tasks/next?wait=0", null, allowNoContent: true, ct);

    /// <summary>Renova a lease. Attempt não vigente (409 task.conflict) sobe como <see cref="ManagerRejectedException"/>.</summary>
    public async Task ProgressAsync(string taskId, TaskProgressRequest request, CancellationToken ct) =>
        await SendSignedAsync<TaskProgressRequest, JsonElement>(
            HttpMethod.Post, $"{ApiPrefix}/tasks/{Uri.EscapeDataString(taskId)}/progress", request, allowNoContent: false, ct);

    /// <summary><c>false</c> quando o attempt já não é o vigente (409 task.conflict): o resultado é descartado, não reenviado.</summary>
    public async Task<bool> SendResultAsync(string taskId, TaskResultRequest request, CancellationToken ct)
    {
        try
        {
            await SendSignedAsync<TaskResultRequest, JsonElement>(
                HttpMethod.Post, $"{ApiPrefix}/tasks/{Uri.EscapeDataString(taskId)}/result", request, allowNoContent: false, ct);

            return true;
        }
        catch (ManagerRejectedException ex) when (ex is { Status: 409, Code: "task.conflict" })
        {
            return false;
        }
    }

    // ---- Transferências (ITransferApi): corpo/retorno binário fora do caminho JSON, sem retentativa interna ----

    public async Task<TransferInfo> GetAsync(string id, CancellationToken ct)
    {
        var raw = await WithClockRetry(() => ExchangeAsync(
            HttpMethod.Get, TransferPath(id), body: null, contentType: null, JsonOnly, extraHeaders: null, signed: true, allowNoContent: false, ct));

        TransferInfo? info;
        try
        {
            info = raw.Payload.Length == 0 ? null : Json.Deserialize<TransferInfo>(raw.Payload);
        }
        catch (JsonException)
        {
            info = null;
        }

        // Campos obrigatórios: nada de caminho/direção "padrão" vindo de JSON incompleto.
        if (info is null || string.IsNullOrEmpty(info.TransferId) || string.IsNullOrEmpty(info.EndpointId)
            || info.Direction is not (TransferProtocol.Send or TransferProtocol.Fetch) || string.IsNullOrEmpty(info.RemotePath)
            || info.ChunkSize <= 0 || info.ReceivedChunks is null)
        {
            throw new ManagerRejectedException((int)raw.Status, "response.invalid", "Metadados de transferência inválidos.");
        }

        return info;
    }

    public async Task<(byte[] Bytes, string Sha256)> GetChunkAsync(string id, int n, CancellationToken ct)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(n);

        var raw = await WithClockRetry(() => ExchangeAsync(
            HttpMethod.Get, ChunkPath(id, n), body: null, contentType: null, OctetOrJson, extraHeaders: null, signed: true, allowNoContent: false, ct));

        var values = raw.Headers.TryGetValues(TransferProtocol.ChunkHashHeader, out var found) ? found.ToList() : [];
        if (values.Count != 1 || !TransferProtocol.IsSha256Hex(values[0]))
        {
            throw new ManagerRejectedException((int)raw.Status, "response.invalid", "Cabeçalho X-Chunk-Sha256 ausente ou inválido.");
        }
        if (raw.Payload.Length > TransferProtocol.MaxChunkBytes)
        {
            throw new ManagerRejectedException((int)raw.Status, "response.invalid", "Chunk maior que o permitido.");
        }
        if (!string.Equals(Convert.ToHexString(SHA256.HashData(raw.Payload)).ToLowerInvariant(), values[0], StringComparison.Ordinal))
        {
            throw new ManagerRejectedException((int)raw.Status, "transfer.chunk_hash", "O chunk recebido não confere com X-Chunk-Sha256.");
        }

        return (raw.Payload, values[0]);
    }

    public async Task PutChunkAsync(string id, int n, byte[] bytes, string sha256, CancellationToken ct)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(n);
        ArgumentNullException.ThrowIfNull(bytes);
        if (bytes.Length > TransferProtocol.MaxChunkBytes)
        {
            throw new ArgumentException("Chunk maior que o permitido.", nameof(bytes));
        }
        if (!TransferProtocol.IsSha256Hex(sha256))
        {
            throw new ArgumentException("SHA-256 deve ter 64 dígitos hex minúsculos.", nameof(sha256));
        }

        await WithClockRetry(() => ExchangeAsync(
            HttpMethod.Put, ChunkPath(id, n), bytes, TransferProtocol.OctetStream, JsonOnly,
            [new(TransferProtocol.ChunkHashHeader, sha256)], signed: true, allowNoContent: false, ct));
    }

    public async Task BeginAsync(string id, long size, CancellationToken ct)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(size);

        await SendSignedAsync<TransferBeginRequest, TransferBeginResponse>(
            HttpMethod.Post, TransferPath(id) + "/begin", new TransferBeginRequest(size), allowNoContent: false, ct);
    }

    public async Task<string> CompleteAsync(string id, string sha256, long size, CancellationToken ct)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(size);
        if (!TransferProtocol.IsSha256Hex(sha256))
        {
            throw new ArgumentException("SHA-256 deve ter 64 dígitos hex minúsculos.", nameof(sha256));
        }

        var response = await SendSignedAsync<TransferCompleteRequest, TransferCompleteResponse>(
            HttpMethod.Post, TransferPath(id) + "/complete", new TransferCompleteRequest(sha256, size), allowNoContent: false, ct);

        return string.IsNullOrEmpty(response.Status)
            ? throw new ManagerRejectedException(200, "response.invalid", "Resposta sem status.")
            : response.Status;
    }

    private static string TransferPath(string id) => $"{ApiPrefix}/transfers/{Uri.EscapeDataString(id)}";

    private static string ChunkPath(string id, int n) =>
        $"{TransferPath(id)}/chunks/{n.ToString(System.Globalization.CultureInfo.InvariantCulture)}";

    private static readonly string[] JsonOnly = ["application/json"];

    private static readonly string[] OctetOrJson = [TransferProtocol.OctetStream, "application/json"];

    private Task<TResponse> PostSignedAsync<TRequest, TResponse>(string route, TRequest body, CancellationToken ct) =>
        SendSignedAsync<TRequest, TResponse>(HttpMethod.Post, ApiPrefix + route, body, allowNoContent: false, ct);

    private Task<TResponse> SendSignedAsync<TRequest, TResponse>(
        HttpMethod method, string pathAndQuery, TRequest? body, bool allowNoContent, CancellationToken ct) =>
        WithClockRetry(() => SendAsync<TRequest, TResponse>(method, pathAndQuery, body, signed: true, ct, allowNoContent));

    /// <summary>
    /// Única repetição do cliente: 401 com server_time (relógio desajustado) — aprende o deslocamento e assina de novo,
    /// uma vez (o Manager recusou a assinatura, então nada foi processado). Rede, 5xx e 4xx NUNCA são repetidos aqui.
    /// </summary>
    private async Task<T> WithClockRetry<T>(Func<Task<T>> send)
    {
        try
        {
            return await send();
        }
        catch (ManagerAuthException ex) when (ex.ServerTime is { } serverTime)
        {
            Interlocked.Exchange(ref _clockOffsetSeconds, serverTime.ToUnixTimeSeconds() - time.GetUtcNow().ToUnixTimeSeconds());

            return await send();
        }
    }

    private async Task<TResponse> SendAsync<TRequest, TResponse>(
        HttpMethod method, string path, TRequest? body, bool signed, CancellationToken ct, bool allowNoContent = false)
    {
        byte[] bytes = body is null ? [] : Json.Serialize(body);
        var raw = await ExchangeAsync(
            method, path, method == HttpMethod.Get ? null : bytes, "application/json", JsonOnly, extraHeaders: null, signed, allowNoContent, ct);

        if (allowNoContent && raw.Status == HttpStatusCode.NoContent)
        {
            return default!;   // 204: sem corpo e sem erro (ex.: nenhuma tarefa disponível)
        }
        if (raw.Payload.Length == 0)
        {
            throw new ManagerRejectedException((int)raw.Status, "response.empty", "Resposta vazia do Manager.");
        }

        return Json.Deserialize<TResponse>(raw.Payload)
            ?? throw new ManagerRejectedException((int)raw.Status, "response.empty", "Resposta vazia do Manager.");
    }

    private sealed record RawResponse(HttpStatusCode Status, byte[] Payload, HttpResponseHeaders Headers);

    /// <summary>
    /// Uma ida ao Manager com os bytes EXATOS de <paramref name="body"/> (assinados como enviados; sem corpo = assinatura
    /// sobre vazio). Sucesso devolve status, corpo e cabeçalhos; erro sobe mapeado por <see cref="MapError"/>.
    /// </summary>
    private async Task<RawResponse> ExchangeAsync(
        HttpMethod method, string path, byte[]? body, string? contentType, string[] accept,
        IEnumerable<KeyValuePair<string, string>>? extraHeaders, bool signed, bool allowNoContent, CancellationToken ct)
    {
        if (signed && runtime.IsRevoked)
        {
            throw new AgentRevokedException();
        }

        var bytes = body ?? [];
        using var request = new HttpRequestMessage(method, path.TrimStart('/'));
        foreach (var type in accept)
        {
            request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue(type));
        }
        request.Headers.UserAgent.ParseAdd($"ToolitAgent/{AgentVersion}");
        foreach (var (name, value) in extraHeaders ?? [])
        {
            request.Headers.TryAddWithoutValidation(name, value);
        }

        if (body is not null)
        {
            request.Content = new ByteArrayContent(bytes);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue(contentType ?? "application/json");
        }

        if (signed)
        {
            Sign(request, method, path, bytes);
        }

        HttpResponseMessage response;
        try
        {
            response = await http.SendAsync(request, ct);
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new ManagerUnavailableException("Tempo esgotado ao contatar o Manager.");
        }
        catch (HttpRequestException ex)
        {
            throw new ManagerUnavailableException("Manager inacessível: " + ex.Message, inner: ex);
        }

        using (response)
        {
            var payload = await response.Content.ReadAsByteArrayAsync(ct);

            if ((allowNoContent && response.StatusCode == HttpStatusCode.NoContent) || response.IsSuccessStatusCode)
            {
                return new RawResponse(response.StatusCode, payload, response.Headers);
            }

            throw MapError(response, payload);
        }
    }

    private void Sign(HttpRequestMessage request, HttpMethod method, string pathAndQuery, byte[] body)
    {
        // A string canônica separa o path da query (crua, ordenada pelo RequestSigner); a query nunca entra no path.
        var split = pathAndQuery.IndexOf('?');
        var path = split < 0 ? pathAndQuery : pathAndQuery[..split];
        var query = split < 0 ? "" : pathAndQuery[(split + 1)..];

        var agentId = runtime.State?.AgentId ?? throw new InvalidOperationException("Agent não registrado.");
        var timestamp = (time.GetUtcNow().ToUnixTimeSeconds() + Interlocked.Read(ref _clockOffsetSeconds)).ToString();
        var nonce = RequestSigner.NewNonce();
        var canonical = RequestSigner.Canonical(method.Method, path, query, timestamp, nonce, body);

        request.Headers.Add("X-Agent-Id", agentId);
        request.Headers.Add("X-Timestamp", timestamp);
        request.Headers.Add("X-Nonce", nonce);
        request.Headers.Add("X-Signature", RequestSigner.Sign(identity.Key, canonical));
        request.Headers.Add("X-Agent-Version", AgentVersion);
    }

    private static Exception MapError(HttpResponseMessage response, byte[] payload)
    {
        string code = "unknown", message = response.ReasonPhrase ?? "erro";
        DateTimeOffset? serverTime = null;

        try
        {
            using var doc = JsonDocument.Parse(payload);
            if (doc.RootElement.TryGetProperty("error", out var error))
            {
                code = error.TryGetProperty("code", out var c) ? c.GetString() ?? code : code;
                message = error.TryGetProperty("message", out var m) ? m.GetString() ?? message : message;
                if (error.TryGetProperty("details", out var d) && d.ValueKind == JsonValueKind.Object
                    && d.TryGetProperty("server_time", out var st) && DateTimeOffset.TryParse(st.GetString(), out var parsed))
                {
                    serverTime = parsed;
                }
            }
        }
        catch (JsonException)
        {
            // Resposta que não é do Manager (proxy, página HTML): tratada pelo status.
        }

        var status = response.StatusCode;

        return status switch
        {
            HttpStatusCode.Forbidden when code == "agent.revoked" => new AgentRevokedException(),
            HttpStatusCode.Unauthorized => new ManagerAuthException(serverTime),
            HttpStatusCode.TooManyRequests => new ManagerUnavailableException("Limite de requisições.", response.Headers.RetryAfter?.Delta),
            >= HttpStatusCode.InternalServerError => new ManagerUnavailableException($"Manager indisponível ({(int)status})."),
            _ => new ManagerRejectedException((int)status, code, message),
        };
    }
}
