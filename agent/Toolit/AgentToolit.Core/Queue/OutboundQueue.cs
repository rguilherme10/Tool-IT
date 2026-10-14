using Microsoft.Data.Sqlite;

namespace AgentToolit.Core.Queue;

public sealed record QueueItem(long Id, string Kind, byte[] Payload, int Attempts);

/// <summary>
/// Fila local persistente (SQLite) para eventos pendentes enquanto o Manager está inacessível.
/// Limitada por quantidade e bytes: ao estourar, descarta o item de menor prioridade e mais antigo (ADR-011).
/// </summary>
public sealed class OutboundQueue : IDisposable
{
    public const string Metrics = "metrics";
    public const string Inventory = "inventory";

    private readonly object _gate = new();
    private readonly SqliteConnection _db;
    private readonly int _maxItems;
    private readonly long _maxBytes;

    static OutboundQueue()
    {
        // SQLite do próprio Windows (winsqlite3.dll, atualizado pelo Windows Update): não distribuímos um SQLite nativo embutido.
        SQLitePCL.Batteries_V2.Init();
    }

    public OutboundQueue(string path, int maxItems, long maxBytes)
    {
        _maxItems = maxItems;
        _maxBytes = maxBytes;
        // Pooling desligado: o arquivo é liberado ao descartar a conexão (evita travar remoção/teste).
        _db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path, Pooling = false }.ToString());
        _db.Open();
        Execute("""
            PRAGMA journal_mode=WAL;
            PRAGMA synchronous=NORMAL;
            CREATE TABLE IF NOT EXISTS items(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                priority INTEGER NOT NULL,
                payload BLOB NOT NULL,
                size INTEGER NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS ix_items_kind ON items(kind, id);
            """);
    }

    /// <returns>Quantidade de itens antigos descartados para respeitar os limites.</returns>
    public int Enqueue(string kind, byte[] payload, int priority, long? unixNow = null)
    {
        lock (_gate)
        {
            using (var cmd = _db.CreateCommand())
            {
                cmd.CommandText = "INSERT INTO items(kind, priority, payload, size, created_at) VALUES($k,$p,$d,$s,$t)";
                cmd.Parameters.AddWithValue("$k", kind);
                cmd.Parameters.AddWithValue("$p", priority);
                cmd.Parameters.AddWithValue("$d", payload);
                cmd.Parameters.AddWithValue("$s", payload.Length);
                cmd.Parameters.AddWithValue("$t", unixNow ?? DateTimeOffset.UtcNow.ToUnixTimeSeconds());
                cmd.ExecuteNonQuery();
            }

            return Trim();
        }
    }

    public IReadOnlyList<QueueItem> Peek(string kind, int limit)
    {
        lock (_gate)
        {
            using var cmd = _db.CreateCommand();
            cmd.CommandText = "SELECT id, kind, payload, attempts FROM items WHERE kind=$k ORDER BY id LIMIT $l";
            cmd.Parameters.AddWithValue("$k", kind);
            cmd.Parameters.AddWithValue("$l", limit);
            using var reader = cmd.ExecuteReader();
            var list = new List<QueueItem>();
            while (reader.Read())
            {
                list.Add(new QueueItem(reader.GetInt64(0), reader.GetString(1), (byte[])reader["payload"], reader.GetInt32(3)));
            }

            return list;
        }
    }

    public void Delete(IEnumerable<long> ids)
    {
        lock (_gate)
        {
            using var tx = _db.BeginTransaction();
            foreach (var id in ids)
            {
                using var cmd = _db.CreateCommand();
                cmd.Transaction = tx;
                cmd.CommandText = "DELETE FROM items WHERE id=$i";
                cmd.Parameters.AddWithValue("$i", id);
                cmd.ExecuteNonQuery();
            }

            tx.Commit();
        }
    }

    public void MarkAttempt(IEnumerable<long> ids)
    {
        lock (_gate)
        {
            foreach (var id in ids)
            {
                using var cmd = _db.CreateCommand();
                cmd.CommandText = "UPDATE items SET attempts = attempts + 1 WHERE id=$i";
                cmd.Parameters.AddWithValue("$i", id);
                cmd.ExecuteNonQuery();
            }
        }
    }

    /// <summary>Contagem somente leitura (comando status): não cria nem altera o arquivo, mesmo com o serviço em execução.</summary>
    public static int? CountPending(string path)
    {
        if (!File.Exists(path))
        {
            return null;
        }

        using var db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = path, Mode = SqliteOpenMode.ReadOnly, Pooling = false }.ToString());
        db.Open();
        using var cmd = db.CreateCommand();
        cmd.CommandText = "SELECT COUNT(*) FROM items";

        return (int)(long)cmd.ExecuteScalar()!;
    }

    public int Count()
    {
        lock (_gate)
        {
            return (int)(long)Scalar("SELECT COUNT(*) FROM items")!;
        }
    }

    public long TotalBytes()
    {
        lock (_gate)
        {
            return Convert.ToInt64(Scalar("SELECT COALESCE(SUM(size),0) FROM items"));
        }
    }

    private int Trim()
    {
        var dropped = 0;
        while (Convert.ToInt64(Scalar("SELECT COUNT(*) FROM items")) > _maxItems
               || Convert.ToInt64(Scalar("SELECT COALESCE(SUM(size),0) FROM items")) > _maxBytes)
        {
            using var cmd = _db.CreateCommand();
            cmd.CommandText = "DELETE FROM items WHERE id = (SELECT id FROM items ORDER BY priority ASC, id ASC LIMIT 1)";
            if (cmd.ExecuteNonQuery() == 0)
            {
                break;
            }

            dropped++;
        }

        return dropped;
    }

    private object? Scalar(string sql)
    {
        using var cmd = _db.CreateCommand();
        cmd.CommandText = sql;

        return cmd.ExecuteScalar();
    }

    private void Execute(string sql)
    {
        using var cmd = _db.CreateCommand();
        cmd.CommandText = sql;
        cmd.ExecuteNonQuery();
    }

    public void Dispose() => _db.Dispose();
}
