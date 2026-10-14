@extends('layouts.app')
@section('title', 'Confirmar senha')

@section('content')
<div class="mx-auto mt-12 max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="mb-1 text-lg font-semibold">Confirme sua senha</h1>
    <p class="mb-4 text-sm text-slate-600">Esta é uma área sensível. Confirme a senha para continuar.</p>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4">
        @csrf
        <label class="block text-sm">Senha
            <input type="password" name="password" required autofocus autocomplete="current-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <button class="w-full rounded bg-slate-900 px-3 py-2 text-white hover:bg-slate-700">Confirmar</button>
    </form>
</div>
@endsection
