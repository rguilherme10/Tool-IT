@extends('layouts.app')
@section('title', 'Grupos')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-semibold">Grupos de computadores</h1>
    <a href="{{ route('groups.create') }}" class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Novo grupo</a>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr><th class="px-3 py-2 font-medium">Grupo</th><th class="px-3 py-2 font-medium">Descrição</th><th class="px-3 py-2 font-medium">Computadores</th><th class="px-3 py-2 font-medium">Subgrupos</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-3 py-2"><span class="inline-block text-slate-300">{{ str_repeat('—', $row['depth']) }}</span>
                        <a class="font-medium text-sky-800 underline-offset-2 hover:underline" href="{{ route('groups.edit', $row['group']->uuid) }}">{{ $row['group']->name }}</a></td>
                    <td class="px-3 py-2 text-slate-600">{{ $row['group']->description ?? '—' }}</td>
                    <td class="px-3 py-2"><a class="underline-offset-2 hover:underline" href="{{ route('endpoints.index', ['group' => $row['group']->uuid]) }}">{{ $row['group']->endpoints_count }}</a></td>
                    <td class="px-3 py-2">{{ $row['group']->children_count }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">Nenhum grupo. Crie grupos para organizar computadores e delimitar o que cada operador enxerga.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<p class="mt-3 text-xs text-slate-500">Papéis atribuídos a um grupo valem para ele e para todos os seus subgrupos.</p>
@endsection
