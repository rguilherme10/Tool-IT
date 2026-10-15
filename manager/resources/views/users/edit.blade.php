@extends('layouts.app')
@section('title', $user->name)

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-semibold">{{ $user->name }}</h1>
    <span class="text-sm text-slate-500">{{ $user->email }}</span>
    <span class="rounded-full px-2 py-0.5 text-xs {{ $user->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $user->is_active ? 'Ativo' : 'Desativado' }}</span>
</div>

<div class="grid gap-4 md:grid-cols-2">
    <section class="rounded-lg border border-slate-200 bg-white p-4 md:col-span-2">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Papéis e escopos</h2>
        <table class="mb-4 w-full text-left text-sm">
            <tbody class="divide-y divide-slate-100">
                @forelse ($user->roleAssignments as $a)
                    <tr>
                        <td class="py-2">{{ $a->role->name }}</td>
                        <td class="py-2 text-slate-600">{{ $a->group ? 'Somente '.$a->group->name.' (e subgrupos)' : 'Global' }}</td>
                        <td class="py-2 text-right">
                            <form method="POST" action="{{ route('users.assignments.destroy', [$user, $a]) }}">@csrf @method('DELETE')
                                <button class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">Remover</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-2 text-amber-700">Sem papéis: este usuário não consegue acessar nada.</td></tr>
                @endforelse
            </tbody>
        </table>
        <form method="POST" action="{{ route('users.assignments.store', $user) }}" class="grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-3">
            @csrf
            <label class="text-sm">Papel
                <select name="role" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    @foreach ($roles as $role)<option value="{{ $role->key }}">{{ $role->name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">Escopo
                <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                    <option value="">Global</option>
                    @foreach ($groups as $g)<option value="{{ $g->uuid }}">Somente {{ $g->name }}</option>@endforeach
                </select>
            </label>
            <div class="flex items-end"><button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Atribuir</button></div>
        </form>
        <p class="mt-2 text-xs text-slate-500">Permissões de sistema (usuários, auditoria, configurações) só funcionam com escopo <strong>global</strong>. O último administrador global não pode ser removido nem desativado.</p>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Redefinir senha</h2>
        <form method="POST" action="{{ route('users.password', $user) }}" class="space-y-2">
            @csrf
            <input type="password" name="password" required autocomplete="new-password" placeholder="Nova senha" class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
            <input type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Confirmar" class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
            <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Redefinir e encerrar sessões</button>
        </form>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Conta</h2>
        <dl class="mb-3 grid grid-cols-[8rem_1fr] gap-y-1 text-sm">
            <dt class="text-slate-500">MFA (TOTP)</dt><dd>{{ $user->two_factor_confirmed_at ? 'ativo' : 'não configurado' }}</dd>
            <dt class="text-slate-500">Último acesso</dt><dd>{{ $user->last_login_at ? $user->last_login_at->format('d/m/Y H:i').' UTC' : 'nunca' }}</dd>
        </dl>
        <form method="POST" action="{{ route('users.active', $user) }}">
            @csrf
            <input type="hidden" name="active" value="{{ $user->is_active ? 0 : 1 }}">
            <button class="rounded border px-3 py-1.5 text-sm {{ $user->is_active ? 'border-red-300 bg-red-50 text-red-800 hover:bg-red-100' : 'border-slate-300 hover:bg-slate-100' }}">
                {{ $user->is_active ? 'Desativar usuário' : 'Reativar usuário' }}
            </button>
        </form>
    </section>
</div>
@endsection
