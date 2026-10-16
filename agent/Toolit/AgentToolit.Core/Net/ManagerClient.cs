using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Core.Net;

/// <summary>
/// Cliente HTTPS do Manager (API v1). Toda chamada, exceto enroll/meta, é assinada com a chave do Agent.
/// Cada tentativa usa timestamp e nonce novos. Validação de certificado é a padrão do sistema — sem bypass.
/// </summary>
public sealed class ManagerClient(
    HttpClient http, AgentIdentity identity, AgentRuntime runtime, TimeProvider time, string agentVersion)
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

    private Task<TResponse> PostSignedAsync<TRequest, TResponse>(string route, TRequest body, CancellationToken ct) =>
        SendSignedAsync<TRequest, TResponse>(HttpMethod.Post, ApiPrefix + route, body, allowNoContent: false, ct);

    private async Task<TResponse> SendSignedAsync<TRequest, TResponse>(
        HttpMethod method, string pathAndQuery, TRequest? body, bool allowNoContent, CancellationToken ct)
    {
        try
        {
            return await SendAsync<TRequest, TResponse>(method, pathAndQuery, body, signed: true, ct, allowNoContent);
        }
        catch (ManagerAuthException ex) when (ex.ServerTime is { } serverTime)
        {
            // Relógio desajustado: aprende o deslocamento e tenta uma única vez com timestamp corrigido.
            Interlocked.Exchange(ref _clockOffsetSeconds, serverTime.ToUnixTimeSeconds() - time.GetUtcNow().ToUnixTimeSeconds());

            return await SendAsync<TRequest, TResponse>(method, pathAndQuery, body, signed: true, ct, allowNoContent);
        }
    }

    private async Task<TResponse> SendAsync<TRequest, TResponse>(
        HttpMethod method, string path, TRequest? body, bool signed, CancellationToken ct, bool allowNoContent = false)
    {
        if (signed && runtime.IsRevoked)
        {
            throw new AgentRevokedException();
        }

        byte[] bytes = body is null ? [] : Json.Serialize(body);
        using var request = new HttpRequestMessage(method, path.TrimStart('/'));
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Headers.UserAgent.ParseAdd($"ToolitAgent/{AgentVersion}");

        if (method != HttpMethod.Get)
        {
            request.Content = new ByteArrayContent(bytes);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
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

            if (allowNoContent && response.StatusCode == HttpStatusCode.NoContent)
            {
                return default!;   // 204: sem corpo e sem erro (ex.: nenhuma tarefa disponível)
            }

            if (response.IsSuccessStatusCode)
            {
                if (payload.Length == 0)
                {
                    throw new ManagerRejectedException((int)response.StatusCode, "response.empty", "Resposta vazia do Manager.");
                }

                return Json.Deserialize<TResponse>(payload)
                    ?? throw new ManagerRejectedException((int)response.StatusCode, "response.empty", "Resposta vazia do Manager.");
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
