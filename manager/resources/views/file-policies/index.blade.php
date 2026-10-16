@extends('layouts.app')
@section('title', 'Política de arquivos')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">Política de transferência de arquivos</h1>
    <a href="{{ route('file-policies.create') }}" class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Nova política</a>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Escopo</th>
                <th class="px-3 py-2 font-medium">Envio para o computador</th>
                <th class="px-3 py-2 font-medium">Busca no computador</th>
                <th class="px-3 py-2 font-medium">Extensões (envio)</th>
                <th class="px-3 py-2 font-medium">Tamanho máx.</th>
                <th class="px-3 py-2 font-medium">Sobrescrever</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($policies as $policy)
                <tr class="align-top">
                    <td class="px-3 py-2"><a class="font-medium text-sky-800 underline-offset-2 hover:underline" href="{{ route('file-policies.edit', $policy->uuid) }}">{{ $policy->group?->name ?? 'Padrão global' }}</a></td>
                    <td class="px-3 py-2">
                        @forelse ($policy->send_dirs as $dir)<div class="font-mono text-xs">{{ $dir }}</div>@empty <span class="text-slate-400">desligado</span>@endforelse
                    </td>
                    <td class="px-3 py-2">
                        @forelse ($policy->fetch_dirs as $dir)<div class="font-mono text-xs">{{ $dir }}</div>@empty <span class="text-slate-400">desligado</span>@endforelse
                    </td>
                    <td class="px-3 py-2">{{ $policy->allowed_extensions === [] ? '—' : implode(' ', $policy->allowed_extensions) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Number::fileSize($policy->max_bytes, precision: 1) }}</td>
                    <td class="px-3 py-2">{{ $policy->allow_overwrite ? 'Sim' : 'Não' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Sem política de transferência: o envio e a busca de arquivos ficam desligados em todos os computadores. Crie uma política global ou por grupo.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">Cada computador usa a política do grupo mais próximo na árvore e, se não houver, a global; sem nenhuma, a transferência fica desligada. Extensões executáveis (exe, dll, bat, ps1, js e similares) são sempre bloqueadas, mesmo se listadas.</p>
@endsection
