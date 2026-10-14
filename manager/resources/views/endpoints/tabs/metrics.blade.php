@php
    $fmtUptime = function (?int $s) {
        if ($s === null) { return '—'; }
        return intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h '.intdiv($s % 3600, 60).'min';
    };
@endphp

<nav aria-label="Período" class="mb-4 flex gap-2 text-sm">
    @foreach ($ranges as $r)
        <a href="{{ route('endpoints.show', ['uuid' => $endpoint->uuid, 'tab' => 'metrics', 'range' => $r]) }}"
           @if ($r === $range) aria-current="true" @endif
           class="rounded border px-2 py-1 {{ $r === $range ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 hover:bg-slate-100' }}">{{ $r }}</a>
    @endforeach
</nav>

@if ($latest === null)
    <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600">Nenhuma métrica recebida ainda. O agente coleta a cada minuto e envia em lote.</div>
@else
<section class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
    @foreach ([
        ['CPU', $latest->cpu !== null ? $latest->cpu.' %' : '—'],
        ['Memória em uso', $latest->memUsed !== null ? $latest->memUsed.' % de '.number_format($latest->memTotalMb / 1024, 1, ',', '.').' GB' : '—'],
        ['Tempo ligado', $fmtUptime($latest->uptime)],
        ['Última amostra', $latest->at->format('d/m H:i:s').' UTC'],
    ] as [$label, $value])
        <div class="rounded-lg border border-slate-200 bg-white p-3">
            <div class="text-xs text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-base font-semibold">{{ $value }}</div>
        </div>
    @endforeach
</section>

<section class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">CPU e memória — {{ $range }} ({{ $sampleCount }} amostras)</h2>
    @if ($sampleCount > 1)
        <svg viewBox="0 0 600 100" preserveAspectRatio="none" role="img" aria-label="Gráfico de uso de CPU e memória" class="h-32 w-full rounded bg-slate-50">
            <polyline points="{{ $memLine }}" fill="none" stroke="#7c3aed" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
            <polyline points="{{ $cpuLine }}" fill="none" stroke="#0369a1" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
        </svg>
        <p class="mt-1 text-xs text-slate-500"><span class="text-sky-700">■</span> CPU % &nbsp; <span class="text-violet-600">■</span> Memória em uso % &nbsp;· escala 0–100 %</p>
    @else
        <p class="text-sm text-slate-500">Amostras insuficientes neste período para desenhar o gráfico.</p>
    @endif
</section>

<div class="grid gap-4 md:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Volumes (última amostra)</h2>
        <ul class="space-y-2 text-sm">
            @forelse ($latest->volumes as $v)
                @php($used = $v['total_gb'] > 0 ? round((1 - $v['free_gb'] / $v['total_gb']) * 100, 1) : 0)
                <li>
                    <div class="flex justify-between"><span>{{ $v['mount'] }}</span><span class="text-slate-500">{{ $used }} % usado · {{ number_format($v['free_gb'], 1, ',', '.') }} GB livres</span></div>
                    <progress class="h-2 w-full" max="100" value="{{ $used }}"></progress>
                </li>
            @empty
                <li class="text-slate-500">Sem volumes reportados.</li>
            @endforelse
        </ul>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Amostras recentes</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-slate-500"><tr><th class="py-1 font-medium">Hora (UTC)</th><th class="py-1 font-medium">CPU</th><th class="py-1 font-medium">Memória</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($recent as $row)
                    <tr>
                        <td class="py-1">{{ \Carbon\CarbonImmutable::parse($row->sampled_at, 'UTC')->format('d/m H:i:s') }}</td>
                        <td class="py-1">{{ $row->cpu_percent !== null ? $row->cpu_percent.' %' : '—' }}</td>
                        <td class="py-1">{{ $row->mem_total_mb > 0 ? round((1 - $row->mem_available_mb / $row->mem_total_mb) * 100, 1).' %' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
<p class="mt-3 text-xs text-slate-500">Temperatura e sensores não são exibidos: só são coletados quando o hardware fornece leitura confiável. O histórico bruto é mantido por {{ config('toolit.retention.metrics_days') }} dias.</p>
@endif
