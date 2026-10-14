namespace AgentToolit.Core.Net;

/// <summary>O Agent só fala com o Manager por HTTPS. HTTP é aceito apenas em loopback (desenvolvimento local).</summary>
public static class ServerUrlPolicy
{
    public static bool TryValidate(string? value, out Uri? uri, out string? error)
    {
        uri = null;
        error = null;

        if (!Uri.TryCreate(value, UriKind.Absolute, out var parsed) || string.IsNullOrEmpty(parsed.Host))
        {
            error = "URL do servidor inválida.";

            return false;
        }

        if (!string.IsNullOrEmpty(parsed.UserInfo) || !string.IsNullOrEmpty(parsed.Query) || !string.IsNullOrEmpty(parsed.Fragment))
        {
            error = "A URL não pode conter credenciais, query ou fragmento.";

            return false;
        }

        var secure = parsed.Scheme == Uri.UriSchemeHttps;
        var loopbackDev = parsed.Scheme == Uri.UriSchemeHttp && parsed.IsLoopback;
        if (!secure && !loopbackDev)
        {
            error = "Use HTTPS (HTTP só é aceito para localhost em desenvolvimento).";

            return false;
        }

        // Garante barra final para que caminhos relativos preservem um eventual prefixo de instalação.
        uri = new UriBuilder(parsed) { Path = parsed.AbsolutePath.TrimEnd('/') + "/" }.Uri;

        return true;
    }
}
