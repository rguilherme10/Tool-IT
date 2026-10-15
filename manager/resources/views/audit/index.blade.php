@extends('layouts.app')
@section('title', 'Auditoria')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">Auditoria</h1>
    <form method="POST" action="{{ route('audit.verify') }}">@csrf
        <button class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm hover:bg-slate-100">Verificar integridade da cadeia</button>
    </form>
</div>

@if (session('integrity_error'))
    <div role="alert" class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900">
        ⚠ Integridade comprometida: {{ session('integrity_error') }}
    </div>
@endif

<form method="GET" class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-6">
    <label class="text-sm md:col-span-2">Ação
        <select name="action" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Todas</option>
            @foreach ($actions as $action)
                <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Origem
        <select name="actor_type" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Todas</option>
            @foreach (['user' => 'Operador', 'agent' => 'Agente', 'system' => 'Sistema'] as $v => $l)
                <option value="{{ $v }}" @selected($filters['actor_type'] === $v)>{{ $l }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Operador (nome/e-mail)
        <input name="user" value="{{ $filters['user'] }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="text-sm">Computador
        <input name="q" value="{{ $filters['q'] }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <div class="grid grid-cols-2 gap-2 md:col-span-2">
        <label class="text-sm">De<input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 w-full rounded border border-slate-300 px-1 py-1"></label>
        <label class="text-sm">Até<input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 w-full rounded border border-slate-300 px-1 py-1"></label>
    </div>
    <div class="flex items-end gap-2 md:col-span-6">
        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Filtrar</button>
        <a href="{{ route('audit.index') }}" class="px-2 py-1.5 text-sm text-slate-600 hover:text-slate-900">Limpar</a>
    </div>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">#</th><th class="px-3 py-2 font-medium">Quando (UTC)</th>
                <th class="px-3 py-2 font-medium">Ação</th><th class="px-3 py-2 font-medium">Quem</th>
                <th class="px-3 py-2 font-medium">Computador</th><th class="px-3 py-2 font-medium">IP</th>
                <th class="px-3 py-2 font-medium">Detalhes</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 align-top">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-3 py-2 text-slate-400">{{ $row->seq }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ substr($row->occurred_at, 0, 19) }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $row->action }}</td>
                    <td class="px-3 py-2">
                        @if ($row->actor_type === 'user') {{ $users[(int) $row->actor_id] ?? 'usuário #'.$row->actor_id }}
                        @elseif ($row->actor_type === 'agent') agente <span class="text-xs text-slate-400">{{ substr((string) $row->actor_id, 0, 8) }}</span>
                        @else sistema @endif
                    </td>
                    <td class="px-3 py-2">{{ $row->endpoint_id ? ($endpoints[$row->endpoint_id] ?? '#'.$row->endpoint_id) : '—' }}</td>
                    <td class="px-3 py-2 text-slate-500">{{ $row->ip_address ?? '—' }}</td>
                    <td class="px-3 py-2">
                        @if ($row->metadata)
                            <details><summary class="cursor-pointer text-xs text-slate-500">ver</summary>
                                <pre class="mt-1 max-w-md overflow-x-auto whitespace-pre-wrap break-all text-xs">{{ json_encode(json_decode($row->metadata, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">Nenhum registro neste filtro.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $rows->links() }}</div>
<p class="mt-3 text-xs text-slate-500">Registros são imutáveis e encadeados por hash; segredos são mascarados na gravação. “Verificar integridade” recalcula toda a cadeia.</p>
@endsection
