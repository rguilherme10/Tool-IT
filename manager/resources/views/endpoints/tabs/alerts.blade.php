@php
    $badge = ['critical' => 'bg-red-100 text-red-800', 'warning' => 'bg-amber-100 text-amber-800', 'info' => 'bg-sky-100 text-sky-800'];
@endphp

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Gravidade</th><th class="px-3 py-2 font-medium">Alerta</th>
                <th class="px-3 py-2 font-medium">Situação</th><th class="px-3 py-2 font-medium">Desde (UTC)</th>
                <th class="relative px-3 py-2 font-medium"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($endpointAlerts as $alert)
                <tr class="{{ $alert->status === \App\Enums\AlertStatus::Resolved ? 'text-slate-400' : '' }}">
                    <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs {{ $badge[$alert->severity->value] }}">{{ $alert->severity->label() }}</span></td>
                    <td class="px-3 py-2">{{ $alert->title }}<div class="text-xs text-slate-500">{{ $alert->rule_name }}</div></td>
                    <td class="px-3 py-2">{{ $alert->status->label() }}@if ($alert->resolved_at) <span class="text-xs">({{ $alert->resolved_at->format('d/m H:i') }})</span>@endif</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $alert->opened_at->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2 text-right">
                        @if ($alert->status === \App\Enums\AlertStatus::Open)
                            @can('alerts.manage', $endpoint)
                                <form method="POST" action="{{ route('alerts.acknowledge', $alert->uuid) }}">@csrf
                                    <button class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">Reconhecer</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">Nenhum alerta para este computador.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<p class="mt-2 text-xs text-slate-500">Mostra os alertas ativos e os 20 resolvidos mais recentes.</p>
