@if ($softwareStatus && $softwareStatus['status'] !== 'ok')
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">A última coleta de software falhou ({{ $softwareStatus['status'] }}@if ($softwareStatus['reason']): {{ $softwareStatus['reason'] }}@endif). A lista abaixo é a da última coleta bem-sucedida.</div>
@endif

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4">
    <input type="hidden" name="tab" value="software">
    <label class="text-sm">Buscar por nome
        <input name="q" value="{{ $filters['q'] }}" class="mt-1 block rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="text-sm">Exibir
        <select name="state" class="mt-1 block rounded border border-slate-300 px-2 py-1">
            <option value="active" @selected($filters['state'] === 'active')>Instalados ({{ $counts['active'] }})</option>
            <option value="removed" @selected($filters['state'] === 'removed')>Removidos ({{ $counts['removed'] }})</option>
            <option value="all" @selected($filters['state'] === 'all')>Todos</option>
        </select>
    </label>
    <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Filtrar</button>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Software</th><th class="px-3 py-2 font-medium">Versão</th>
                <th class="px-3 py-2 font-medium">Fabricante</th><th class="px-3 py-2 font-medium">Instalado em</th>
                <th class="px-3 py-2 font-medium">Origem</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($items as $item)
                <tr class="{{ $item->removed_at ? 'text-slate-400' : '' }}">
                    <td class="px-3 py-2">{{ $item->name }}@if ($item->removed_at) <span class="text-xs">(removido em {{ $item->removed_at->format('d/m/Y') }})</span>@endif</td>
                    <td class="px-3 py-2">{{ $item->version ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $item->publisher ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $item->install_date?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $item->source }}{{ $item->arch ? ' · '.$item->arch : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Nenhum software encontrado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $items->links() }}</div>
