@extends('layouts.app')
@section('title', 'Usuários')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">Usuários</h1>
    <a href="{{ route('users.create') }}" class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Novo usuário</a>
</div>

<form method="GET" class="mb-4 flex gap-2">
    <input name="q" value="{{ $q }}" placeholder="Buscar por nome ou e-mail" class="w-full max-w-sm rounded border border-slate-300 px-2 py-1 text-sm">
    <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Buscar</button>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr><th class="px-3 py-2 font-medium">Usuário</th><th class="px-3 py-2 font-medium">Papéis</th><th class="px-3 py-2 font-medium">MFA</th><th class="px-3 py-2 font-medium">Último acesso (UTC)</th><th class="px-3 py-2 font-medium">Situação</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($users as $user)
                <tr class="{{ $user->is_active ? '' : 'text-slate-400' }}">
                    <td class="px-3 py-2"><a class="font-medium text-sky-800 underline-offset-2 hover:underline" href="{{ route('users.edit', $user) }}">{{ $user->name }}</a><div class="text-xs text-slate-500">{{ $user->email }}</div></td>
                    <td class="px-3 py-2">
                        @forelse ($user->roleAssignments as $a)
                            <div class="text-xs">{{ $a->role->name }} <span class="text-slate-500">· {{ $a->group?->name ?? 'global' }}</span></div>
                        @empty
                            <span class="text-xs text-amber-700">sem papel (sem acesso)</span>
                        @endforelse
                    </td>
                    <td class="px-3 py-2">{{ $user->two_factor_confirmed_at ? 'ativo' : '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $user->last_login_at?->format('d/m/Y H:i') ?? 'nunca' }}</td>
                    <td class="px-3 py-2">{{ $user->is_active ? 'Ativo' : 'Desativado' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">Nenhum usuário encontrado.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
