@extends('layouts.app')
@section('title', 'Entrar')

@section('content')
<div class="mx-auto mt-12 max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="mb-4 text-lg font-semibold">Entrar no Toolit</h1>
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf
        <label class="block text-sm">E-mail
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm">Senha
            <input type="password" name="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <button class="w-full rounded bg-slate-900 px-3 py-2 text-white hover:bg-slate-700">Entrar</button>
    </form>
</div>
@endsection
