<div class="grid gap-4 md:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Identificação</h2>
        <dl class="grid grid-cols-[9rem_1fr] gap-y-1 text-sm">
            <dt class="text-slate-500">ID</dt><dd class="break-all">{{ $endpoint->uuid }}</dd>
            <dt class="text-slate-500">Grupo</dt><dd>{{ $endpoint->group?->name ?? '—' }}</dd>
            <dt class="text-slate-500">Fabricante / modelo</dt><dd>{{ trim(($endpoint->manufacturer ?? '').' '.($endpoint->model ?? '')) ?: '—' }}</dd>
            <dt class="text-slate-500">Nº de série</dt><dd>{{ $endpoint->serial_number ?? '—' }}</dd>
            <dt class="text-slate-500">Sistema</dt><dd>{{ trim(($endpoint->os_name ?? '—').' '.($endpoint->os_version ?? '')) }} {{ $endpoint->os_arch }}</dd>
            <dt class="text-slate-500">Domínio / grupo</dt><dd>{{ $endpoint->domain ?? '—' }}</dd>
            @if ($sensitive)
                <dt class="text-slate-500">Usuário atual</dt><dd>{{ $endpoint->current_user ?? '—' }}</dd>
                <dt class="text-slate-500">Endereços IP</dt><dd>{{ $endpoint->ip_addresses ? implode(', ', $endpoint->ip_addresses) : '—' }}</dd>
            @endif
            <dt class="text-slate-500">Último contato</dt><dd>{{ $endpoint->last_seen_at?->format('d/m/Y H:i:s') ?? 'nunca' }} <span class="text-slate-400">UTC</span></dd>
            <dt class="text-slate-500">Último inventário</dt><dd>{{ $endpoint->last_inventory_at?->format('d/m/Y H:i:s') ?? 'ainda não recebido' }}</dd>
        </dl>
        @unless ($sensitive)
            <p class="mt-3 text-xs text-slate-500">Seu perfil não inclui usuário logado, endereços IP/MAC nem a lista de software.</p>
        @endunless
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Agente</h2>
        @if ($endpoint->activeInstallation)
            <dl class="grid grid-cols-[9rem_1fr] gap-y-1 text-sm">
                <dt class="text-slate-500">Instalação</dt><dd class="break-all">{{ $endpoint->activeInstallation->uuid }}</dd>
                <dt class="text-slate-500">Versão</dt><dd>{{ $endpoint->activeInstallation->agent_version ?? '—' }}</dd>
                <dt class="text-slate-500">Registrado em</dt><dd>{{ $endpoint->activeInstallation->enrolled_at->format('d/m/Y H:i') }}</dd>
            </dl>
            @can('revoke', $endpoint)
                <form method="POST" action="{{ route('endpoints.revoke', $endpoint->uuid) }}" class="mt-4 space-y-2 border-t border-slate-200 pt-4">
                    @csrf
                    <label class="block text-sm">Motivo da revogação
                        <input name="reason" required minlength="3" maxlength="255" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    </label>
                    <button class="rounded border border-red-300 bg-red-50 px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Revogar agente</button>
                    <p class="text-xs text-slate-500">O agente perde o acesso imediatamente. Para reativar é preciso um novo registro.</p>
                </form>
            @endcan
        @else
            <p class="text-sm text-slate-600">Nenhuma instalação ativa (revogada ou substituída).</p>
        @endif
    </section>
</div>

@can('endpoints.manage', $endpoint)
<section class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Gerenciar computador</h2>
    <div class="grid gap-4 md:grid-cols-2">
        <form method="POST" action="{{ route('endpoints.group', $endpoint->uuid) }}" class="space-y-2">
            @csrf
            <label class="block text-sm">Grupo
                <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    @if ($canUngroup)<option value="">(sem grupo)</option>@endif
                    @foreach ($assignableGroups as $g)
                        <option value="{{ $g->uuid }}" @selected($endpoint->endpoint_group_id === $g->id)>{{ $g->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Mover</button>
        </form>
        <form method="POST" action="{{ route('endpoints.status', $endpoint->uuid) }}" class="space-y-2">
            @csrf
            @if ($endpoint->status === \App\Enums\EndpointStatus::Disabled)
                <input type="hidden" name="status" value="active">
                <p class="text-sm text-amber-700">Este computador está <strong>desativado</strong>: o agente é recusado.</p>
                <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Reativar computador</button>
            @else
                <input type="hidden" name="status" value="disabled">
                <p class="text-sm text-slate-600">Desativar recusa o agente (403) e encerra os alertas, sem apagar o histórico.</p>
                <button class="rounded border border-red-300 bg-red-50 px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Desativar computador</button>
            @endif
        </form>
    </div>
</section>
@endcan

@if ($audit !== null)
<section class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Auditoria recente</h2>
    <table class="w-full text-left text-sm">
        <tbody class="divide-y divide-slate-100">
            @forelse ($audit as $row)
                <tr><td class="py-1 pr-3 text-slate-500">{{ $row->occurred_at }}</td><td class="py-1 pr-3">{{ $row->action }}</td><td class="py-1 text-slate-500">{{ $row->actor_type }}</td></tr>
            @empty
                <tr><td class="py-2 text-slate-500">Sem eventos.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
@endif
