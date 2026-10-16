using System.Text.Json;
using AgentToolit.Core.Protocol;

namespace AgentToolit.Core.Execution.Handlers;

/// <summary>
/// Serializa uma lista já ordenada por prioridade mantendo o maior prefixo que cabe no limite de bytes,
/// sempre como JSON íntegro (nunca cortado no meio). Busca binária: o tamanho cresce com o número de itens.
/// </summary>
internal static class BoundedJson
{
    public static string Serialize<T>(IReadOnlyList<T> items, Func<IReadOnlyList<T>, bool, object> envelope, int maxBytes = TaskRunner.MaxOutputBytes)
    {
        byte[] Render(int count) => JsonSerializer.SerializeToUtf8Bytes(envelope(Take(items, count), count < items.Count), Json.Options);

        var all = Render(items.Count);
        if (all.Length <= maxBytes)
        {
            return System.Text.Encoding.UTF8.GetString(all);
        }

        int low = 0, high = items.Count - 1;   // high: maior contagem ainda candidata
        byte[] best = Render(0);
        while (low <= high)
        {
            var mid = low + ((high - low) / 2);
            var bytes = Render(mid);
            if (bytes.Length <= maxBytes)
            {
                best = bytes;
                low = mid + 1;
            }
            else
            {
                high = mid - 1;
            }
        }

        return System.Text.Encoding.UTF8.GetString(best);
    }

    private static IReadOnlyList<T> Take<T>(IReadOnlyList<T> items, int count) =>
        count >= items.Count ? items : items.Take(count).ToList();
}
