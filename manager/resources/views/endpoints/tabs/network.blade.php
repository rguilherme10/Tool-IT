<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Interface</th><th class="px-3 py-2 font-medium">Tipo</th>
                <th class="px-3 py-2 font-medium">MAC</th><th class="px-3 py-2 font-medium">Endereços IP</th>
                <th class="px-3 py-2 font-medium">Estado</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($interfaces as $nic)
                <tr>
                    <td class="px-3 py-2">{{ $nic->name }}<div class="text-xs text-slate-500">{{ $nic->description }}</div></td>
                    <td class="px-3 py-2">{{ $nic->type ?? '—' }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $nic->mac ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $nic->ips ? implode(', ', $nic->ips) : '—' }}</td>
                    <td class="px-3 py-2 {{ $nic->up ? 'text-emerald-700' : 'text-slate-500' }}">{{ $nic->up ? 'Ativa' : 'Inativa' }}@if ($nic->speed_mbps) · {{ $nic->speed_mbps }} Mb/s @endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Nenhuma interface de rede recebida ainda.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
