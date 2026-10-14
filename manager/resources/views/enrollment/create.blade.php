@extends('layouts.app')
@section('title', 'Novo agente')

@section('content')
<h1 class="mb-4 text-xl font-semibold">Registrar novo agente</h1>

<form method="POST" action="{{ route('enrollment.store') }}" class="max-w-lg space-y-4 rounded-lg border border-slate-200 bg-white p-4">
    @csrf
    <label class="block text-sm">Grupo de computadores
        <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            @if ($canUngrouped)<option value="">Sem grupo</option>@endif
            @foreach ($groups as $group)
                <option value="{{ $group->uuid }}">{{ $group->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block text-sm">Identificação (opcional)
        <input name="label" maxlength="120" value="{{ old('label') }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">Validade (horas)
            <input type="number" name="ttl_hours" min="1" max="72" value="{{ old('ttl_hours', 24) }}" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        </label>
        <label class="block text-sm">Usos
            @if ($canMulti)
                <input type="number" name="max_uses" min="1" max="500" value="{{ old('max_uses', 1) }}" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            @else
                <input type="hidden" name="max_uses" value="1">
                <input value="1 (uso único)" disabled class="mt-1 w-full rounded border border-slate-200 bg-slate-100 px-2 py-1">
            @endif
        </label>
    </div>
    <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-700">Gerar token</button>
    <p class="text-xs text-slate-500">O token é exibido uma única vez e fica armazenado apenas como hash.</p>
</form>
@endsection
