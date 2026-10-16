@can('tasks.cancel', $task->endpoint)
    @if ($task->state === \App\Enums\TaskState::Running && $task->cancel_requested_at)
        <span class="text-xs text-slate-500">Cancelamento pedido</span>
    @elseif (! $task->state->isFinal())
        <form method="POST" action="{{ route('tasks.cancel', $task->uuid) }}">@csrf
            <button class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-100">{{ $task->state === \App\Enums\TaskState::Running ? 'Pedir cancelamento' : 'Cancelar' }}</button>
        </form>
    @endif
@endcan
