@extends('layouts.app')
@section('title', $policy->exists ? 'Editar política de arquivos' : 'Nova política de arquivos')

@section('content')
<h1 class="mb-4 text-xl font-semibold">{{ $policy->exists ? 'Editar política de transferência' : 'Nova política de transferência' }}</h1>

<form method="POST" action="{{ $policy->exists ? route('file-policies.update', $policy->uuid) : route('file-policies.store') }}"
      class="max-w-2xl space-y-4 rounded-lg border border-slate-200 bg-white p-4">
    @csrf
    @if ($policy->exists) @method('PUT') @endif

    @if ($policy->exists)
        <div class="text-sm">Escopo
            <div class="mt-1 w-full rounded border border-slate-200 bg-slate-100 px-2 py-1">{{ $policy->group?->name ?? 'Padrão global' }}</div>
            <span class="text-xs text-slate-500">O escopo não pode ser trocado: remova a política e crie outra.</span>
        </div>
    @else
        <label class="block text-sm">Escopo
            <select name="group" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                <option value="" @selected(old('group', '') === '') @disabled(in_array('global', $taken, true))>Padrão global{{ in_array('global', $taken, true) ? ' (já tem política)' : '' }}</option>
                @foreach ($groups as $g)
                    @php($has = in_array('group:'.$g->id, $taken, true))
                    <option value="{{ $g->uuid }}" @selected(old('group') === $g->uuid) @disabled($has)>{{ $g->name }}{{ $has ? ' (já tem política)' : '' }}</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">Uma política por escopo. Vale para o grupo e seus subgrupos, até que um subgrupo tenha a sua.</span>
        </label>
    @endif

    <label class="block text-sm">Diretórios de envio (do Manager para o computador)
        <textarea name="send_dirs" rows="3" spellcheck="false" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono text-xs">{{ old('send_dirs', $values['send_dirs']) }}</textarea>
        <span class="text-xs text-slate-500">Um diretório absoluto por linha (até 20), como C:\Pasta\Sub. Vazio = envio desligado. Raiz de unidade, C:\Windows, C:\Program Files* e C:\Users inteiro são recusados.</span>
    </label>

    <label class="block text-sm">Diretórios de busca (do computador para o Manager)
        <textarea name="fetch_dirs" rows="3" spellcheck="false" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono text-xs">{{ old('fetch_dirs', $values['fetch_dirs']) }}</textarea>
        <span class="text-xs text-slate-500">Um diretório absoluto por linha (até 20). Vazio = busca desligada.</span>
    </label>

    <label class="block text-sm">Extensões permitidas no envio
        <textarea name="allowed_extensions" rows="2" spellcheck="false" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 font-mono text-xs">{{ old('allowed_extensions', $values['allowed_extensions']) }}</textarea>
        <span class="text-xs text-slate-500">Minúsculas, sem ponto, separadas por espaço, vírgula ou linha. Extensões executáveis ou de script (exe, dll, bat, cmd, ps1, js, lnk, reg...) são sempre bloqueadas e recusadas aqui.</span>
    </label>

    <label class="block text-sm">Tamanho máximo por arquivo (bytes)
        <input name="max_bytes" type="number" required min="1" max="1073741824" step="1" value="{{ old('max_bytes', $values['max_bytes']) }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
        <span class="text-xs text-slate-500">De 1 byte a 1 GiB (1073741824). O padrão, 104857600, equivale a 100 MiB.</span>
    </label>

    <div class="text-sm">
        <input type="hidden" name="allow_overwrite" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="allow_overwrite" value="1" @checked(old('allow_overwrite', $values['allow_overwrite'] ? '1' : '0') === '1')> Permitir sobrescrever arquivo existente no destino</label>
    </div>

    <div class="flex gap-2">
        <button class="rounded bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-700">Salvar</button>
        <a href="{{ route('file-policies.index') }}" class="px-3 py-2 text-sm text-slate-600 hover:text-slate-900">Cancelar</a>
    </div>
</form>

@if ($policy->exists)
<form method="POST" action="{{ route('file-policies.destroy', $policy->uuid) }}" class="mt-6 max-w-2xl space-y-2 rounded-lg border border-red-200 bg-red-50 p-4">
    @csrf @method('DELETE')
    <h2 class="text-sm font-semibold text-red-800">Remover política</h2>
    <p class="text-xs text-red-900">Sem esta política, os computadores do escopo passam a usar a do grupo acima ou a global; se não houver nenhuma, a transferência fica desligada.</p>
    <label class="flex items-center gap-2 text-sm text-red-900"><input type="checkbox" name="confirm" value="1" required> Confirmo a remoção.</label>
    <button class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm text-red-800 hover:bg-red-100">Remover</button>
</form>
@endif
@endsection
