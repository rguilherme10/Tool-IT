@extends('layouts.app')
@section('title', 'Computadores')

@section('content')
<h1 class="mb-4 text-xl font-semibold">Computadores</h1>

<form method="GET" class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-4">
    <label class="text-sm">Hostname
        <input name="hostname" value="{{ $filters['hostname'] ?? '' }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="text-sm">Grupo
        <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Todos</option>
            @foreach ($groups as $group)
                <option value="{{ $group->uuid }}" @selected(($filters['group'] ?? '') === $group->uuid)>{{ $group->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Contato
        <select name="status" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Qualquer</option>
            <option value="online" @selected(($filters['status'] ?? '') === 'online')>Online</option>
            <option value="offline" @selected(($filters['status'] ?? '') === 'offline')>Sem contato</option>
            <option value="never" @selected(($filters['status'] ?? '') === 'never')>Nunca visto</option>
        </select>
    </label>
    <div class="flex items-end gap-2">
        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Filtrar</button>
        <a href="{{ route('endpoints.index') }}" class="px-2 py-1.5 text-sm text-slate-600 hover:text-slate-900">Limpar</a>
    </div>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Hostname</th>
                <th class="px-3 py-2 font-medium">Grupo</th>
                <th class="px-3 py-2 font-medium">Sistema</th>
                <th class="px-3 py-2 font-medium">Agente</th>
                <th class="px-3 py-2 font-medium">Último contato</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($endpoints as $endpoint)
                @php($online = $endpoint->last_seen_at && $endpoint->last_seen_at->gte($cutoff))
                <tr>
                    <td class="px-3 py-2"><a class="font-medium text-slate-900 underline-offset-2 hover:underline" href="{{ route('endpoints.show', $endpoint->uuid) }}">{{ $endpoint->hostname }}</a></td>
                    <td class="px-3 py-2">{{ $endpoint->group?->name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $endpoint->os_name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $endpoint->agent_version ?? '—' }}</td>
                    <td class="px-3 py-2">
                        <span class="{{ $online ? 'text-emerald-700' : 'text-slate-500' }}">{{ $online ? 'Online' : ($endpoint->last_seen_at ? 'Sem contato' : 'Nunca visto') }}</span>
                        @if ($endpoint->last_seen_at)<span class="text-slate-400"> · {{ $endpoint->last_seen_at->diffForHumans() }}</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Nenhum computador encontrado. Registre um agente em “Novo agente”.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $endpoints->links() }}</div>
@endsection
