@extends('layouts.app')
@section('title', 'Token de enrollment')

@section('content')
<h1 class="mb-2 text-xl font-semibold">Token gerado</h1>
<p class="mb-4 text-sm text-slate-600">Copie agora: ele <strong>não será exibido novamente</strong>. Informe-o ao instalador do agente no computador de destino.</p>

<div class="max-w-2xl rounded-lg border border-amber-300 bg-amber-50 p-4">
    <code class="block break-all font-mono text-sm">{{ $token }}</code>
</div>

<p class="mt-4 text-sm text-slate-600">O instalador do agente (tarefa 012) ainda não existe; até lá o registro é feito pelo próprio agente com <code>--server</code> e <code>--token</code>.</p>
<a href="{{ route('enrollment.create') }}" class="mt-4 inline-block text-sm underline">Gerar outro token</a>
@endsection
