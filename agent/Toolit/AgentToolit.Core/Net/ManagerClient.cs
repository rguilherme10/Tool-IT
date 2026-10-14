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

    private async Task<TResponse> PostSignedAsync<TRequest, TResponse>(string route, TRequest body, CancellationToken ct)
    {
        try
        {
            return await SendAsync<TRequest, TResponse>(HttpMethod.Post, ApiPrefix + route, body, signed: true, ct);
        }
        catch (ManagerAuthException ex) when (ex.ServerTime is { } serverTime)
        {
            // Relógio desajustado: aprende o deslocamento e tenta uma única vez com timestamp corrigido.
            Interlocked.Exchange(ref _clockOffsetSeconds, serverTime.ToUnixTimeSeconds() - time.GetUtcNow().ToUnixTimeSeconds());

            return await SendAsync<TRequest, TResponse>(HttpMethod.Post, ApiPrefix + route, body, signed: true, ct);
        }
    }

    private async Task<TResponse> SendAsync<TRequest, TResponse>(
        HttpMethod method, string path, TRequest? body, bool signed, CancellationToken ct)
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

            if (response.IsSuccessStatusCode)
            {
                return Json.Deserialize<TResponse>(payload)
                    ?? throw new ManagerRejectedException((int)response.StatusCode, "response.empty", "Resposta vazia do Manager.");
            }

            throw MapError(response, payload);
        }
    }

    private void Sign(HttpRequestMessage request, HttpMethod method, string path, byte[] body)
    {
        var agentId = runtime.State?.AgentId ?? throw new InvalidOperationException("Agent não registrado.");
        var timestamp = (time.GetUtcNow().ToUnixTimeSeconds() + Interlocked.Read(ref _clockOffsetSeconds)).ToString();
        var nonce = RequestSigner.NewNonce();
        var canonical = RequestSigner.Canonical(method.Method, path, query: "", timestamp, nonce, body);

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
