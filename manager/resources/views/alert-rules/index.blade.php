@extends('layouts.app')
@section('title', 'Regras de alerta')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">Regras de alerta</h1>
    <a href="{{ route('alert-rules.create') }}" class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Nova regra</a>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Regra</th>
                <th class="px-3 py-2 font-medium">Condição</th>
                <th class="px-3 py-2 font-medium">Persistência</th>
                <th class="px-3 py-2 font-medium">Escopo</th>
                <th class="px-3 py-2 font-medium">Ativos</th>
                <th class="px-3 py-2 font-medium">Estado</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rules as $rule)
                <tr class="{{ $rule->enabled ? '' : 'text-slate-400' }}">
                    <td class="px-3 py-2"><a class="font-medium text-sky-800 underline-offset-2 hover:underline" href="{{ route('alert-rules.edit', $rule->uuid) }}">{{ $rule->name }}</a>
                        <div class="text-xs text-slate-500">{{ $rule->type->label() }} · {{ $rule->severity->label() }}</div></td>
                    <td class="px-3 py-2">
                        @switch($rule->type)
                            @case(\App\Enums\AlertRuleType::AgentOffline)
                                sem contato por mais de {{ intdiv((int) $rule->threshold, 60) }} min (limpa em ≤ {{ intdiv((int) $rule->effectiveClearThreshold(), 60) }} min)
                                @break
                            @case(\App\Enums\AlertRuleType::AgentOutdated)
                                versão &lt; {{ $rule->params['min_version'] ?? config('toolit.agent.min_version') }}
                                @break
                            @default
                                &gt; {{ rtrim(rtrim(number_format($rule->threshold, 2, ',', ''), '0'), ',') }} % (limpa em ≤ {{ rtrim(rtrim(number_format($rule->effectiveClearThreshold(), 2, ',', ''), '0'), ',') }} %)
                        @endswitch
                    </td>
                    <td class="px-3 py-2">
                        @if ($rule->type->usesMetrics()) por {{ intdiv($rule->min_duration_seconds, 60) }} min @else imediata @endif
                        @if ($rule->repeat_interval_seconds > 0)<div class="text-xs text-slate-500">repete a cada {{ intdiv($rule->repeat_interval_seconds, 60) }} min</div>@endif
                    </td>
                    <td class="px-3 py-2">{{ $rule->group?->name ?? 'Todos os computadores' }}</td>
                    <td class="px-3 py-2">{{ $rule->active_alerts_count }}</td>
                    <td class="px-3 py-2">{{ $rule->enabled ? 'Ativa' : 'Desligada' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Nenhuma regra. Rode <code>php artisan db:seed --class=AlertRulesSeeder</code> para criar as regras padrão, ou crie uma.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">Um alerta só abre depois que a condição persiste pelo tempo da regra e só fecha depois de ficar abaixo do limite de “limpeza” (histerese). Leituras isoladas e lacunas de dados nunca abrem nem fecham alertas.</p>
@endsection
