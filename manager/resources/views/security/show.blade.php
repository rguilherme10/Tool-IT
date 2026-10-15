@extends('layouts.app')
@section('title', 'Segurança')

@section('content')
<h1 class="mb-4 text-xl font-semibold">Segurança da conta</h1>

<section class="mb-4 max-w-xl rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 font-medium">Alterar senha</h2>
    @if ($errors->updatePassword->any())
        <ul role="alert" class="mb-3 list-inside list-disc rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            @foreach ($errors->updatePassword->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif
    <form method="POST" action="{{ route('user-password.update') }}" class="space-y-2">
        @csrf @method('PUT')
        <input type="password" name="current_password" required autocomplete="current-password" placeholder="Senha atual" class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
        <input type="password" name="password" required autocomplete="new-password" placeholder="Nova senha (mín. 12 caracteres)" class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
        <input type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Confirmar nova senha" class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Alterar senha</button>
    </form>
</section>

<section class="max-w-xl rounded-lg border border-slate-200 bg-white p-4">
    <h2 class="mb-2 font-medium">Verificação em duas etapas (TOTP)</h2>

    @if ($enabled)
        <p class="mb-3 text-sm text-emerald-700">Ativada.</p>
        <h3 class="mb-1 text-sm font-medium">Códigos de recuperação</h3>
        <p class="mb-2 text-xs text-slate-500">Guarde-os em local seguro. Cada código funciona uma vez.</p>
        <ul class="mb-4 grid grid-cols-2 gap-1 font-mono text-sm">
            @foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach
        </ul>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf
                <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Gerar novos códigos</button>
            </form>
            <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
                <button class="rounded border border-red-300 bg-red-50 px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Desativar</button>
            </form>
        </div>
    @elseif ($pending)
        <p class="mb-3 text-sm text-slate-600">Escaneie o QR code no aplicativo autenticador e informe o código para concluir.</p>
        <div class="mb-3 inline-block bg-white p-2">{!! $qrSvg !!}</div>
        <p class="mb-3 text-xs text-slate-500">Chave manual: <code class="font-mono">{{ $secret }}</code></p>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex gap-2">
            @csrf
            <input name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="Código de 6 dígitos" class="rounded border border-slate-300 px-2 py-1">
            <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Confirmar</button>
        </form>
    @else
        <p class="mb-3 text-sm text-slate-600">Administradores precisam de verificação em duas etapas para usar o painel.</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
            <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Ativar</button>
        </form>
    @endif
</section>
@endsection
