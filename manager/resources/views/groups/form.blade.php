@extends('layouts.app')
@section('title', $group->exists ? 'Editar grupo' : 'Novo grupo')

@section('content')
<h1 class="mb-4 text-xl font-semibold">{{ $group->exists ? 'Editar grupo' : 'Novo grupo' }}</h1>

<form method="POST" action="{{ $group->exists ? route('groups.update', $group->uuid) : route('groups.store') }}"
      class="max-w-xl space-y-4 rounded-lg border border-slate-200 bg-white p-4">
    @csrf
    @if ($group->exists) @method('PUT') @endif

    <label class="block text-sm">Nome
        <input name="name" required maxlength="120" value="{{ old('name', $group->name) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="block text-sm">Descrição (opcional)
        <input name="description" maxlength="500" value="{{ old('description', $group->description) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
    </label>
    <label class="block text-sm">Grupo pai
        <select name="parent" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
            <option value="">(nenhum — nível principal)</option>
            @foreach ($parents as $p)
                <option value="{{ $p->uuid }}" @selected(old('parent', $group->parent_id === $p->id ? $p->uuid : '') === $p->uuid)>{{ $p->name }}</option>
            @endforeach
        </select>
    </label>
    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-700">Salvar</button>
        <a href="{{ route('groups.index') }}" class="px-3 py-2 text-sm text-slate-600 hover:text-slate-900">Cancelar</a>
    </div>
</form>

@if ($group->exists)
<form method="POST" action="{{ route('groups.destroy', $group->uuid) }}" class="mt-6 max-w-xl space-y-2 rounded-lg border border-red-200 bg-red-50 p-4">
    @csrf @method('DELETE')
    <h2 class="text-sm font-semibold text-red-800">Excluir grupo</h2>
    <p class="text-xs text-red-900">Só é possível excluir grupos vazios (sem computadores, subgrupos, papéis atribuídos ou regras de alerta).</p>
    <label class="flex items-center gap-2 text-sm text-red-900"><input type="checkbox" name="confirm" value="1" required> Confirmo a exclusão.</label>
    <button class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Excluir</button>
</form>
@endif
@endsection
