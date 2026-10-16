@php
    $user = auth()->user();
    $oldType = old('type');
@endphp

@if (count($creatableTypes) > 0)
    <section class="mb-6" aria-labelledby="nova-tarefa">
        <h2 id="nova-tarefa" class="mb-2 text-base font-semibold">Nova tarefa</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($creatableTypes as $type)
                @php($fields = \App\Support\Tasks\TaskForm::fields($type))
                <form method="POST" action="{{ route('tasks.store', ['uuid' => $endpoint->uuid]) }}" class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="font-medium">{{ \App\Support\Tasks\TaskForm::label($type) }}</div>
                    <p class="text-xs text-slate-500">{{ \App\Support\Tasks\TaskForm::help($type) }}</p>
                    @if (in_array('pid', $fields, true))
                        <label class="text-sm">PID
                            <input name="pid" inputmode="numeric" maxlength="9" value="{{ $oldType === $type ? old('pid') : '' }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        </label>
                    @endif
                    @if (in_array('name', $fields, true))
                        <label class="text-sm">{{ $type === 'process.kill' ? 'Nome do processo' : 'Nome do serviço' }}
                            <input name="name" maxlength="128" value="{{ $oldType === $type && is_string(old('name')) ? old('name') : '' }}" class="mt-1 w-full rounded border border-slate-300 px-2 py-1">
                        </label>
                    @endif
                    <div class="mt-auto pt-1">
                        <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Criar tarefa</button>
                    </div>
                </form>
            @endforeach
        </div>
    </section>
@endif

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
    <table class="w-full text-left text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
                <th class="px-3 py-2 font-medium">Tarefa</th><th class="px-3 py-2 font-medium">Estado</th>
                <th class="px-3 py-2 font-medium">Risco</th><th class="px-3 py-2 font-medium">Criada por</th>
                <th class="px-3 py-2 font-medium">Criada em (UTC)</th>
                <th class="relative px-3 py-2 font-medium"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tasks as $task)
                <tr data-task-row>
                    <td class="px-3 py-2">
                        <a class="font-medium underline-offset-2 hover:underline" href="{{ route('tasks.show', $task->uuid) }}">{{ \App\Support\Tasks\TaskForm::label($task->type) }}</a>
                        @if ($task->params)
                            <div class="text-xs text-slate-500">{{ collect($task->params)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : '…'))->implode(' · ') }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">@include('tasks._state')</td>
                    <td class="px-3 py-2">@include('tasks._risk')</td>
                    <td class="px-3 py-2">{{ $task->creator?->name ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-slate-600">{{ $task->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @include('tasks._decision')
                            @include('tasks._cancel')
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Nenhuma tarefa para este computador.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tasks->links() }}</div>
<p class="mt-3 text-xs text-slate-500">O agente consulta tarefas a cada cerca de 10 segundos. Tarefas de alto risco só são executadas depois da aprovação de outra pessoa; tarefas que ninguém pega expiram em {{ (int) config('toolit.tasks.pending_ttl_minutes', 30) }} minutos.</p>
