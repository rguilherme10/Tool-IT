@php
    $hw = $inventory?->hardware;
    $peripherals = $inventory?->peripherals;
    $status = $inventory?->section_status ?? [];
    $labels = ['system' => 'Sistema', 'hardware' => 'Hardware', 'software' => 'Software', 'network' => 'Rede', 'peripherals' => 'Periféricos'];
    $statusText = ['ok' => 'Coletado', 'unavailable' => 'Indisponível', 'error' => 'Erro na coleta'];
@endphp

@if ($inventory === null)
    <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600">Nenhum inventário recebido ainda. O agente envia o inventário logo após o registro e a cada 24 horas.</div>
@else
<section class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Estado da última coleta</h2>
    <ul class="grid gap-1 text-sm md:grid-cols-2">
        @foreach ($labels as $key => $label)
            @php($s = $status[$key] ?? null)
            <li class="flex justify-between gap-2 border-b border-slate-100 py-1">
                <span>{{ $label }}</span>
                @if ($s)
                    <span class="{{ $s['status'] === 'ok' ? 'text-emerald-700' : 'text-amber-700' }}">{{ $statusText[$s['status']] ?? $s['status'] }}@if ($s['reason']) ({{ $s['reason'] }})@endif</span>
                @else
                    <span class="text-slate-400">não recebido</span>
                @endif
            </li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-slate-500">Quando uma seção está indisponível, o painel mostra o último dado coletado com sucesso. Última coleta: {{ $inventory->collected_at?->format('d/m/Y H:i') }} UTC.</p>
</section>

@if ($hw)
<div class="grid gap-4 md:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Processador e memória</h2>
        @forelse ($hw['cpus'] ?? [] as $cpu)
            <p class="text-sm">{{ $cpu['name'] ?? '—' }} <span class="text-slate-500">· {{ $cpu['cores'] ?? '?' }} núcleos / {{ $cpu['threads'] ?? '?' }} threads</span></p>
        @empty
            <p class="text-sm text-slate-500">Processador não detectado.</p>
        @endforelse
        <p class="mt-2 text-sm">Memória RAM: {{ isset($hw['memory_total_mb']) ? number_format($hw['memory_total_mb'] / 1024, 1, ',', '.').' GB' : '—' }}</p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Placa-mãe e BIOS</h2>
        <p class="text-sm">{{ $hw['baseboard']['manufacturer'] ?? '—' }} {{ $hw['baseboard']['product'] ?? '' }}</p>
        <p class="text-sm text-slate-600">BIOS {{ $hw['bios']['vendor'] ?? '—' }} {{ $hw['bios']['version'] ?? '' }} @if (!empty($hw['bios']['release_date']))({{ $hw['bios']['release_date'] }})@endif</p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4 md:col-span-2">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Discos e volumes</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <ul class="space-y-1 text-sm">
                @forelse ($hw['disks'] ?? [] as $disk)
                    <li>{{ $disk['model'] ?? 'Disco' }} <span class="text-slate-500">· {{ isset($disk['size_gb']) ? number_format($disk['size_gb'], 0, ',', '.').' GB' : '—' }} · {{ $disk['media_type'] ?? '' }}</span></li>
                @empty
                    <li class="text-slate-500">Discos físicos não detectados.</li>
                @endforelse
            </ul>
            <ul class="space-y-2 text-sm">
                @forelse ($hw['volumes'] ?? [] as $vol)
                    <li>
                        <div class="flex justify-between"><span>{{ $vol['mount'] ?? '?' }} {{ $vol['label'] ?? '' }}</span><span class="text-slate-500">{{ number_format($vol['free_gb'] ?? 0, 1, ',', '.') }} GB livres de {{ number_format($vol['total_gb'] ?? 0, 1, ',', '.') }} GB</span></div>
                        <progress class="h-2 w-full" max="100" value="{{ $vol['used_percent'] ?? 0 }}"></progress>
                    </li>
                @empty
                    <li class="text-slate-500">Volumes não detectados.</li>
                @endforelse
            </ul>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4 md:col-span-2">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Vídeo</h2>
        <ul class="space-y-1 text-sm">
            @forelse ($hw['gpus'] ?? [] as $gpu)
                <li>{{ $gpu['name'] ?? '—' }} <span class="text-slate-500">· driver {{ $gpu['driver_version'] ?? '—' }}</span></li>
            @empty
                <li class="text-slate-500">Adaptador de vídeo não detectado.</li>
            @endforelse
        </ul>
    </section>
</div>

@if (!empty($hw['not_detected']))
    <section class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <strong>Não puderam ser consultados:</strong>
        @foreach ($hw['not_detected'] as $nd) {{ $nd['component'] ?? '?' }} ({{ $nd['reason'] ?? '?' }})@if (!$loop->last), @endif @endforeach
    </section>
@endif
@endif

<section class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Periféricos</h2>
    @if ($peripherals)
        <div class="grid gap-4 md:grid-cols-2 text-sm">
            <div>
                <h3 class="mb-1 font-medium">Monitores</h3>
                <ul class="space-y-1">
                    @forelse ($peripherals['monitors'] ?? [] as $m)<li>{{ $m['name'] ?? 'Monitor' }} <span class="text-slate-500">{{ $m['manufacturer'] ?? '' }} {{ $m['year'] ?? '' }}</span></li>@empty<li class="text-slate-500">Nenhum monitor detectado.</li>@endforelse
                </ul>
            </div>
            <div>
                <h3 class="mb-1 font-medium">Impressoras</h3>
                <ul class="space-y-1">
                    @forelse ($peripherals['printers'] ?? [] as $p)<li>{{ $p['name'] ?? '—' }} @if (!empty($p['is_default']))<span class="text-slate-500">(padrão)</span>@endif</li>@empty<li class="text-slate-500">Nenhuma impressora detectada.</li>@endforelse
                </ul>
            </div>
        </div>
    @else
        <p class="text-sm text-slate-500">Periféricos não coletados (ver estado da coleta acima).</p>
    @endif
</section>
@endif
