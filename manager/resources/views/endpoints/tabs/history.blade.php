@php
    $categories = [
        'os' => 'Sistema operacional', 'device' => 'Equipamento', 'serial' => 'Número de série', 'domain' => 'Domínio', 'hostname' => 'Nome do computador',
        'cpu' => 'Processador', 'memory' => 'Memória', 'disk' => 'Disco', 'volume' => 'Volume', 'gpu' => 'Vídeo', 'baseboard' => 'Placa-mãe', 'bios' => 'BIOS',
        'monitor' => 'Monitor', 'printer' => 'Impressora', 'network' => 'Interface de rede',
    ];
    $types = ['added' => 'adicionado', 'removed' => 'removido', 'changed' => 'alterado'];
    $typeColor = ['added' => 'text-emerald-700', 'removed' => 'text-rose-700', 'changed' => 'text-amber-700'];
@endphp

<p class="mb-3 text-sm text-slate-600">Mudanças detectadas entre uma coleta de inventário e a anterior. Espaço livre, uso de disco e endereços IP não contam como mudança.</p>

@forelse ($entries as $entry)
    <section class="mb-3 rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold">
            Coleta de {{ $entry['at']?->format('d/m/Y H:i') }} UTC
            @if ($entry['initial'])<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-600">Primeira coleta</span>@endif
        </h2>

        @if ($entry['items'])
            <ul class="space-y-1 text-sm">
                @foreach ($entry['items'] as $item)
                    <li>
                        <span class="font-medium">{{ $categories[$item['category']] ?? $item['category'] }}</span>
                        <span class="{{ $typeColor[$item['type']] ?? '' }}">{{ $types[$item['type']] ?? $item['type'] }}:</span>
                        @if ($item['type'] === 'changed')
                            {{ $item['before'] }} <span aria-label="para">→</span> {{ $item['after'] }}
                        @else
                            {{ $item['before'] ?? $item['after'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($entry['software'])
            @php($sw = $entry['software'])
            <div class="{{ $entry['items'] ? 'mt-3 border-t border-slate-100 pt-3' : '' }} text-sm">
                <h3 class="mb-1 font-medium">Software</h3>
                @if ($sw['added_count'])
                    <p class="text-emerald-700">{{ $sw['added_count'] }} instalado(s): <span class="text-slate-700">{{ implode(', ', $sw['added']) }}@if ($sw['added_count'] > count($sw['added'])) e mais {{ $sw['added_count'] - count($sw['added']) }}@endif</span></p>
                @endif
                @if ($sw['removed_count'])
                    <p class="text-rose-700">{{ $sw['removed_count'] }} removido(s): <span class="text-slate-700">{{ implode(', ', $sw['removed']) }}@if ($sw['removed_count'] > count($sw['removed'])) e mais {{ $sw['removed_count'] - count($sw['removed']) }}@endif</span></p>
                @endif
                @if ($sw['reappeared_count'])
                    <p class="text-amber-700">{{ $sw['reappeared_count'] }} reinstalado(s) (já constavam como removidos)</p>
                @endif
            </div>
        @endif

        @if ($entry['restricted'])
            <p class="{{ $entry['items'] ? 'mt-2' : '' }} text-xs text-slate-500">Há alterações de software ou rede restritas ao seu perfil.</p>
        @endif
    </section>
@empty
    <div class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600">Nenhuma mudança registrada. As mudanças aparecem a partir da segunda coleta de inventário (a cada 24 horas).</div>
@endforelse

<div class="mt-4">{{ $snapshots->links() }}</div>
