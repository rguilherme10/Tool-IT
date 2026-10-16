using System.Net;
using System.Text;
using AgentToolit.Core.Config;
using AgentToolit.Core.Identity;
using AgentToolit.Core.Net;

namespace AgentToolit.Tests.Support;

/// <summary>Diretório temporário isolado por teste (removido ao final).</summary>
public sealed class TempDir : IDisposable
{
    public string Path { get; } = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "toolit-agent-tests-" + Guid.NewGuid().ToString("N"));

    public TempDir() => Directory.CreateDirectory(Path);

    public AgentPaths Paths()
    {
        var paths = new AgentPaths(System.IO.Path.Combine(Path, "data"));
        paths.EnsureCreated(restrictAcl: false);

        return paths;
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(Path, recursive: true);
        }
        catch (IOException)
        {
            // Arquivo ainda em uso por antivírus/indexador: o diretório temporário será limpo pelo sistema.
        }
    }
}

/// <summary>Manager simulado: registra cada request (incluindo os bytes do corpo) e responde via delegate.</summary>
public sealed class StubHandler(Func<CapturedRequest, HttpResponseMessage> respond) : HttpMessageHandler
{
    public List<CapturedRequest> Requests { get; } = [];

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var body = request.Content is null ? [] : await request.Content.ReadAsByteArrayAsync(cancellationToken);
        var captured = new CapturedRequest(request.Method.Method, request.RequestUri!.AbsolutePath, request.RequestUri.Query,
            request.Headers.ToDictionary(h => h.Key, h => h.Value.First(), StringComparer.OrdinalIgnoreCase), body)
        {
            ContentType = request.Content?.Headers.ContentType?.MediaType,
        };
        Requests.Add(captured);

        return respond(captured);
    }

    public static HttpResponseMessage Json(HttpStatusCode status, string json) =>
        new(status) { Content = new StringContent(json, Encoding.UTF8, "application/json") };

    public static HttpResponseMessage Error(HttpStatusCode status, string code, string? detailsJson = null) =>
        Json(status, "{\"error\":{\"code\":\"" + code + "\",\"message\":\"m\",\"details\":" + (detailsJson ?? "{}") + ",\"request_id\":\"x\"}}");
}

public sealed record CapturedRequest(string Method, string Path, string Query, Dictionary<string, string> Headers, byte[] Body)
{
    public string BodyText => Encoding.UTF8.GetString(Body);

    /// <summary>Content-Type do corpo (só o tipo, sem parâmetros), ou null sem corpo.</summary>
    public string? ContentType { get; init; }
}

public sealed class FakeTime(DateTimeOffset start) : TimeProvider
{
    private DateTimeOffset _now = start;

    public override DateTimeOffset GetUtcNow() => _now;

    public void Advance(TimeSpan by) => _now += by;
}

public static class Enrolled
{
    /// <summary>Cliente já "registrado" contra um Manager simulado. Estado persistido em <paramref name="dir"/>.</summary>
    public static (ManagerClient Client, AgentRuntime Runtime, AgentIdentity Identity, StubHandler Handler) Create(
        TempDir dir, Func<CapturedRequest, HttpResponseMessage> respond, TimeProvider? time = null)
    {
        var paths = dir.Paths();
        var runtime = new AgentRuntime(new AgentStateStore(paths));
        runtime.SetEnrolled(new AgentState { AgentId = "11111111-2222-4333-8444-555555555555", EndpointId = "e", EnrolledAt = DateTimeOffset.UtcNow });
        var identity = AgentIdentity.Generate();
        var handler = new StubHandler(respond);
        var http = new HttpClient(handler) { BaseAddress = new Uri("https://manager.test/") };

        return (new ManagerClient(http, identity, runtime, time ?? TimeProvider.System, "0.1.0"), runtime, identity, handler);
    }
}
