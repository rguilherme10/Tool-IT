@extends('layouts.app')
@section('title', 'Aprovações')

@section('content')
<div class="mb-4 flex flex-wrap items-baseline gap-3">
    <h1 class="text-xl font-semibold">Aprovações</h1>
    <span class="text-sm text-slate-500">{{ $tasks->total() }} tarefa(s) aguardando</span>
</div>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Computador</th><th class="px-3 py-2 font-medium">Tarefa</th>
                <th class="px-3 py-2 font-medium">Risco</th><th class="px-3 py-2 font-medium">Criada por</th>
                <th class="px-3 py-2 font-medium">Criada em (UTC)</th>
                <th class="relative px-3 py-2 font-medium"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tasks as $task)
                <tr data-task-row>
                    <td class="px-3 py-2">
                        <a class="font-medium underline-offset-2 hover:underline" href="{{ route('endpoints.show', ['uuid' => $task->endpoint->uuid, 'tab' => 'tasks']) }}">{{ $task->endpoint->hostname }}</a>
                        <div class="text-xs text-slate-500">{{ $task->endpoint->group?->name ?? 'sem grupo' }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <a class="font-medium underline-offset-2 hover:underline" href="{{ route('tasks.show', $task->uuid) }}">{{ \App\Support\Tasks\TaskForm::label($task->type) }}</a>
                        @if ($task->params)
                            <div class="text-xs text-slate-500">{{ collect($task->params)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : '…'))->implode(' · ') }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">@include('tasks._risk')</td>
                    <td class="px-3 py-2">{{ $task->creator?->name ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-slate-600">{{ $task->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @include('tasks._decision')
                            @if ((int) $task->created_by === (int) $user->id)
                                <span class="text-xs text-slate-500">Outra pessoa precisa aprovar</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Nenhuma tarefa aguardando aprovação.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tasks->links() }}</div>
<p class="mt-3 text-xs text-slate-500">Quem criou a tarefa não pode aprová-la nem rejeitá-la. Tarefas não aprovadas expiram sozinhas.</p>
@endsection
