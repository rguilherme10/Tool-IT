@extends('layouts.app')
@section('title', 'Alertas')

@php
    $badge = [
        'critical' => 'bg-red-100 text-red-800',
        'warning' => 'bg-amber-100 text-amber-800',
        'info' => 'bg-sky-100 text-sky-800',
    ];
@endphp

@section('content')
<div class="mb-4 flex flex-wrap items-baseline gap-3">
    <h1 class="text-xl font-semibold">Alertas</h1>
    <span class="text-sm text-slate-500">{{ $counts['open'] }} aberto(s) · {{ $counts['acknowledged'] }} reconhecido(s)</span>
</div>

<form method="GET" class="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 md:grid-cols-5">
    <label class="text-sm">Situação
        <select name="status" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            @foreach (['active' => 'Ativos (abertos + reconhecidos)', 'open' => 'Abertos', 'acknowledged' => 'Reconhecidos', 'resolved' => 'Resolvidos', 'all' => 'Todos'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Gravidade
        <select name="severity" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Todas</option>
            @foreach (['critical' => 'Crítico', 'warning' => 'Atenção', 'info' => 'Informação'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['severity'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Grupo
        <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">Todos</option>
            @foreach ($groups as $group)
                <option value="{{ $group->uuid }}" @selected($filters['group'] === $group->uuid)>{{ $group->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="text-sm">Computador
        <input name="q" value="{{ $filters['q'] }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <div class="flex items-end gap-2">
        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Filtrar</button>
        <a href="{{ route('alerts.index') }}" class="px-2 py-1.5 text-sm text-slate-600 hover:text-slate-900">Limpar</a>
    </div>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Gravidade</th>
                <th class="px-3 py-2 font-medium">Computador</th>
                <th class="px-3 py-2 font-medium">Alerta</th>
                <th class="px-3 py-2 font-medium">Situação</th>
                <th class="px-3 py-2 font-medium">Desde (UTC)</th>
                <th class="relative px-3 py-2 font-medium"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($alerts as $alert)
                <tr>
                    <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $badge[$alert->severity->value] }}">{{ $alert->severity->label() }}</span></td>
                    <td class="px-3 py-2">
                        <a class="font-medium underline-offset-2 hover:underline" href="{{ route('endpoints.show', ['uuid' => $alert->endpoint->uuid, 'tab' => 'alerts']) }}">{{ $alert->endpoint->hostname }}</a>
                        <div class="text-xs text-slate-500">{{ $alert->endpoint->group?->name ?? 'sem grupo' }}</div>
                    </td>
                    <td class="px-3 py-2">{{ $alert->title }}
                        <div class="text-xs text-slate-500">{{ $alert->rule_name }}@if ($alert->repeat_count > 0) · repetido {{ $alert->repeat_count }}×@endif</div>
                    </td>
                    <td class="px-3 py-2">{{ $alert->status->label() }}
                        @if ($alert->resolved_at)<div class="text-xs text-slate-500">resolvido {{ $alert->resolved_at->format('d/m H:i') }}</div>@endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-slate-600">{{ $alert->opened_at->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2 text-right">
                        @if ($alert->status === \App\Enums\AlertStatus::Open)
                            @can('alerts.manage', $alert->endpoint)
                                <form method="POST" action="{{ route('alerts.acknowledge', $alert->uuid) }}">@csrf
                                    <button class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">Reconhecer</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Nenhum alerta neste filtro.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $alerts->links() }}</div>
<p class="mt-3 text-xs text-slate-500">Os alertas são avaliados a cada minuto a partir do histórico de métricas. Reconhecer apenas indica ciência: o alerta só fecha quando a condição some (com histerese).</p>
@endsection
