@extends('layouts.app')
@section('title', \App\Support\Tasks\TaskForm::label($task->type))

@php
    $fmt = fn ($date) => $date?->format('d/m/Y H:i:s') ?? '—';
    $params = json_encode($task->params ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

@section('content')
<div class="mb-1 text-sm">
    <a class="text-slate-500 hover:text-slate-800" href="{{ route('endpoints.show', ['uuid' => $task->endpoint->uuid, 'tab' => 'tasks']) }}">&larr; Tarefas de {{ $task->endpoint->hostname }}</a>
</div>
<div class="mb-4 flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-semibold">{{ \App\Support\Tasks\TaskForm::label($task->type) }}</h1>
    @include('tasks._state')
    <span class="text-sm text-slate-500">Risco</span> @include('tasks._risk')
</div>

<div class="mb-4 flex flex-wrap items-center gap-3">
    @include('tasks._decision')
    @include('tasks._cancel')
</div>

<dl class="mb-6 grid gap-x-6 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm sm:grid-cols-2">
    <div><dt class="text-slate-500">Computador</dt><dd><a class="underline-offset-2 hover:underline" href="{{ route('endpoints.show', ['uuid' => $task->endpoint->uuid]) }}">{{ $task->endpoint->hostname }}</a> <span class="text-xs text-slate-500">{{ $task->endpoint->group?->name ?? 'sem grupo' }}</span></dd></div>
    <div><dt class="text-slate-500">Tipo</dt><dd><code>{{ $task->type }}</code></dd></div>
    <div><dt class="text-slate-500">Criada por</dt><dd>{{ $task->creator?->name ?? '—' }}</dd></div>
    <div><dt class="text-slate-500">Criada em (UTC)</dt><dd>{{ $fmt($task->created_at) }}</dd></div>
    <div><dt class="text-slate-500">Aprovada por</dt><dd>{{ $task->approver?->name ?? ($task->risk->requiresApproval() ? 'ainda não aprovada' : 'não precisa de aprovação') }}</dd></div>
    <div><dt class="text-slate-500">Aprovada em (UTC)</dt><dd>{{ $fmt($task->approved_at) }}</dd></div>
    <div><dt class="text-slate-500">Expira em (UTC)</dt><dd>{{ $fmt($task->expires_at) }}</dd></div>
    @if ($task->cancel_requested_at)
        <div><dt class="text-slate-500">Cancelamento pedido em (UTC)</dt><dd>{{ $fmt($task->cancel_requested_at) }}</dd></div>
    @endif
    @if ($task->result_summary)
        <div class="sm:col-span-2"><dt class="text-slate-500">Resumo do resultado</dt>
            <dd>{{ collect($task->result_summary)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }}</dd>
        </div>
    @endif
</dl>

<h2 class="mb-2 text-base font-semibold">Parâmetros</h2>
@if (($task->params ?? []) === [])
    <p class="mb-6 text-sm text-slate-500">Esta tarefa não tem parâmetros.</p>
@else
    <pre class="mb-6 overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 text-xs">{{ $params }}</pre>
@endif

<h2 class="mb-2 text-base font-semibold">Tentativas</h2>
@forelse ($task->attempts as $attempt)
    <section class="mb-4 rounded-lg border border-slate-200 bg-white p-4 text-sm">
        <h3 class="mb-2 font-medium">Tentativa {{ $attempt->number }}
            <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $attempt->status ?? 'em andamento' }}</span>
        </h3>
        <dl class="mb-3 grid gap-x-6 gap-y-1 sm:grid-cols-3">
            <div><dt class="text-slate-500">Pega em (UTC)</dt><dd>{{ $fmt($attempt->claimed_at) }}</dd></div>
            <div><dt class="text-slate-500">Código de saída</dt><dd>{{ $attempt->exit_code ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Erro</dt><dd>{{ $attempt->error ?? '—' }}</dd></div>
        </dl>
        @if ($attempt->truncated)
            <p class="mb-2 text-xs text-amber-700">Saída truncada em 16 KiB.</p>
        @endif
        <div class="grid gap-3 lg:grid-cols-2">
            <div>
                <div class="mb-1 text-xs font-medium text-slate-500">Saída padrão (stdout)</div>
                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded border border-slate-200 bg-slate-50 p-2 text-xs">{{ $attempt->stdout }}</pre>
            </div>
            <div>
                <div class="mb-1 text-xs font-medium text-slate-500">Saída de erro (stderr)</div>
                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded border border-slate-200 bg-slate-50 p-2 text-xs">{{ $attempt->stderr }}</pre>
            </div>
        </div>
    </section>
@empty
    <p class="text-sm text-slate-500">Nenhuma tentativa ainda: o agente ainda não pegou esta tarefa.</p>
@endforelse
@endsection
