using System.Text;
using Microsoft.Extensions.Logging;

namespace AgentToolit.Core.Logging;

/// <summary>
/// Log técnico local: um arquivo por dia em logs\agent-AAAAMMDD.log, limite de tamanho por arquivo e retenção em dias.
/// Toda mensagem passa por <see cref="LogRedactor"/>. Nunca registra corpos de requests nem chaves.
/// </summary>
public sealed class FileLoggerProvider : ILoggerProvider
{
    private readonly string _directory;
    private readonly long _maxFileBytes;
    private readonly TimeProvider _time;
    private readonly object _gate = new();
    private bool _truncatedNoted;

    public FileLoggerProvider(string directory, int retentionDays, long maxFileBytes = 10L * 1024 * 1024, TimeProvider? time = null)
    {
        _directory = directory;
        _maxFileBytes = maxFileBytes;
        _time = time ?? TimeProvider.System;
        Directory.CreateDirectory(directory);
        Purge(retentionDays);
    }

    public ILogger CreateLogger(string categoryName) => new FileLogger(this, categoryName);

    public void Dispose()
    {
    }

    private void Purge(int retentionDays)
    {
        var cutoff = _time.GetUtcNow().UtcDateTime.AddDays(-retentionDays);
        foreach (var file in Directory.EnumerateFiles(_directory, "agent-*.log"))
        {
            try
            {
                if (File.GetLastWriteTimeUtc(file) < cutoff)
                {
                    File.Delete(file);
                }
            }
            catch (IOException)
            {
                // Arquivo em uso: fica para a próxima limpeza.
            }
        }
    }

    internal void Write(LogLevel level, string category, string message, Exception? exception)
    {
        var now = _time.GetUtcNow();
        var path = Path.Combine(_directory, $"agent-{now:yyyyMMdd}.log");
        var line = new StringBuilder()
            .Append(now.ToString("yyyy-MM-ddTHH:mm:ss.fffZ")).Append(' ')
            .Append(level.ToString().ToUpperInvariant()).Append(' ')
            .Append(category).Append(" - ")
            .Append(message.ReplaceLineEndings(" "));
        if (exception is not null)
        {
            line.Append(" | ").Append(exception.GetType().Name).Append(": ").Append(exception.Message.ReplaceLineEndings(" "));
        }

        var text = LogRedactor.Redact(line.ToString()) + Environment.NewLine;

        lock (_gate)
        {
            try
            {
                if (File.Exists(path) && new FileInfo(path).Length > _maxFileBytes)
                {
                    if (!_truncatedNoted)
                    {
                        File.AppendAllText(path, "-- limite de tamanho do log diário atingido; mensagens descartadas --" + Environment.NewLine);
                        _truncatedNoted = true;
                    }

                    return;
                }

                File.AppendAllText(path, text, Encoding.UTF8);
            }
            catch (IOException)
            {
                // Falha de log nunca derruba o serviço.
            }
        }
    }

    private sealed class FileLogger(FileLoggerProvider owner, string category) : ILogger
    {
        public IDisposable? BeginScope<TState>(TState state)
            where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => logLevel != LogLevel.None;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
        {
            if (IsEnabled(logLevel))
            {
                owner.Write(logLevel, category, formatter(state, exception), exception);
            }
        }
    }
}
