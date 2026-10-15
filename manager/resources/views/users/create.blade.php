@extends('layouts.app')
@section('title', 'Novo usuário')

@section('content')
<h1 class="mb-4 text-xl font-semibold">Novo usuário</h1>

<form method="POST" action="{{ route('users.store') }}" class="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-4">
    @csrf
    <label class="block text-sm">Nome
        <input name="name" required maxlength="255" value="{{ old('name') }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="block text-sm">E-mail
        <input type="email" name="email" required maxlength="255" value="{{ old('email') }}" autocomplete="off" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">Senha provisória
            <input type="password" name="password" required autocomplete="new-password" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
        <label class="block text-sm">Confirmar senha
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
    </div>
    <p class="-mt-2 text-xs text-slate-500">Mínimo de 12 caracteres, com maiúsculas, minúsculas e números. O usuário deve trocá-la em “Segurança”.</p>

    <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">Papel inicial (opcional)
            <select name="role" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                <option value="">Nenhum (sem acesso até receber um papel)</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->key }}" @selected(old('role') === $role->key)>{{ $role->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">Escopo
            <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                <option value="">Global (todos os computadores)</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->uuid }}" @selected(old('group') === $g->uuid)>Somente {{ $g->name }} (e subgrupos)</option>
                @endforeach
            </select>
        </label>
    </div>
    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-700">Criar</button>
        <a href="{{ route('users.index') }}" class="px-3 py-2 text-sm text-slate-600 hover:text-slate-900">Cancelar</a>
    </div>
</form>
@endsection
