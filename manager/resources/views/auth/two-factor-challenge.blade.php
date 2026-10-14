@extends('layouts.app')
@section('title', 'Verificação em duas etapas')

@section('content')
<div class="mx-auto mt-12 max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <h1 class="mb-1 text-lg font-semibold">Verificação em duas etapas</h1>
    <p class="mb-4 text-sm text-slate-600">Informe o código do aplicativo autenticador ou um código de recuperação.</p>
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
        @csrf
        <label class="block text-sm">Código
            <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm">Ou código de recuperação
            <input name="recovery_code" autocomplete="one-time-code"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <button class="w-full rounded bg-slate-900 px-3 py-2 text-white hover:bg-slate-700">Verificar</button>
    </form>
</div>
@endsection
