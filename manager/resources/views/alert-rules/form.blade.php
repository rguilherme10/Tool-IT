@extends('layouts.app')
@section('title', $rule->exists ? 'Editar regra' : 'Nova regra')

@php
    $type = old('type', $rule->type?->value ?? 'cpu_usage');
    $pct = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $v = fn (string $field, $default = '') => old($field, $default);
    $metric = in_array($type, ['disk_usage', 'memory_usage', 'cpu_usage'], true);
@endphp

@section('content')
<h1 class="mb-4 text-xl font-semibold">{{ $rule->exists ? 'Editar regra' : 'Nova regra de alerta' }}</h1>

<form method="POST" action="{{ $rule->exists ? route('alert-rules.update', $rule->uuid) : route('alert-rules.store') }}"
      class="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-4">
    @csrf
    @if ($rule->exists) @method('PUT') @endif

    <label class="block text-sm">Tipo
        @if ($rule->exists)
            <input value="{{ $rule->type->label() }}" disabled class="mt-1 w-full rounded border border-slate-200 bg-slate-100 px-2 py-1">
        @else
            <select name="type" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                @foreach ($types as $t)
                    <option value="{{ $t->value }}" @selected($type === $t->value)>{{ $t->label() }}</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">Os campos abaixo se aplicam conforme o tipo; limites inválidos são recusados.</span>
        @endif
    </label>

    <label class="block text-sm">Nome
        <input name="name" required maxlength="120" value="{{ $v('name', $rule->name) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>

    <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">Gravidade
            <select name="severity" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                @foreach ($severities as $s)
                    <option value="{{ $s->value }}" @selected($v('severity', $rule->severity?->value) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">Escopo
            <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                <option value="">Todos os computadores</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->uuid }}" @selected($v('group', $rule->endpoint_group_id === $g->id ? $g->uuid : '') === $g->uuid)>{{ $g->name }} (e subgrupos)</option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">Limite (% — ou segundos para “sem comunicação”)
            <input name="threshold" type="number" step="0.01" value="{{ $v('threshold', $pct($rule->threshold)) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
        <label class="block text-sm">Limite de “limpeza” (histerese)
            <input name="clear_threshold" type="number" step="0.01" value="{{ $v('clear_threshold', $pct($rule->clear_threshold)) }}" placeholder="automático" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <span class="text-xs text-slate-500">Deve ser menor que o limite.</span>
        </label>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <label class="block text-sm">Persistir por (min)
            <input name="min_duration_minutes" type="number" min="0" max="1440" value="{{ $v('min_duration_minutes', intdiv((int) $rule->min_duration_seconds, 60)) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
        <label class="block text-sm">Limpo por (min)
            <input name="clear_duration_minutes" type="number" min="0" max="1440" value="{{ $v('clear_duration_minutes', intdiv((int) $rule->clear_duration_seconds, 60)) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
        <label class="block text-sm">Repetir a cada (min)
            <input name="repeat_interval_minutes" type="number" min="0" max="10080" value="{{ $v('repeat_interval_minutes', intdiv((int) $rule->repeat_interval_seconds, 60)) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
    </div>
    <p class="-mt-2 text-xs text-slate-500">“Persistir” e “Limpo por” valem para disco, memória e CPU. 0 em “Repetir” desliga a repetição.</p>

    <label class="block text-sm">Versão mínima do agente (somente “versão desatualizada”)
        <input name="min_version" maxlength="32" placeholder="0.1.0" value="{{ $v('min_version', $rule->params['min_version'] ?? '') }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="enabled" value="1" @checked($v('enabled', $rule->enabled ?? true))> Regra ativa
    </label>

    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-700">Salvar</button>
        <a href="{{ route('alert-rules.index') }}" class="px-3 py-2 text-sm text-slate-600 hover:text-slate-900">Cancelar</a>
    </div>
</form>

@if ($rule->exists)
<form method="POST" action="{{ route('alert-rules.destroy', $rule->uuid) }}" class="mt-6 max-w-xl space-y-2 rounded-lg border border-red-200 bg-red-50 p-4">
    @csrf @method('DELETE')
    <h2 class="text-sm font-semibold text-red-800">Excluir regra</h2>
    <label class="flex items-center gap-2 text-sm text-red-900"><input type="checkbox" name="confirm" value="1" required> Confirmo a exclusão; os alertas ativos desta regra serão encerrados.</label>
    <button class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Excluir</button>
</form>
@endif
@endsection
