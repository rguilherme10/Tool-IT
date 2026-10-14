using System.Text.RegularExpressions;

namespace AgentToolit.Core.Logging;

/// <summary>Mascara segredos antes de qualquer log: tokens de enrollment, assinaturas, senhas e blobs longos em Base64.</summary>
public static partial class LogRedactor
{
    [GeneratedRegex(@"tk_[A-Za-z0-9_\-]{6,}")]
    private static partial Regex Token();

    [GeneratedRegex(@"(?i)\b(signature|authorization|password|passwd|secret|token|private[_ ]?key)\b(\s*[:=]\s*)\S+")]
    private static partial Regex KeyValue();

    [GeneratedRegex(@"[A-Za-z0-9+/_\-]{64,}={0,2}")]
    private static partial Regex Blob();

    public static string Redact(string text)
    {
        text = Token().Replace(text, "tk_[redacted]");
        text = KeyValue().Replace(text, "$1$2[redacted]");

        return Blob().Replace(text, "[redacted-blob]");
    }
}
