{{-- Aprovar/rejeitar. Esconder para o criador é só conforto: a separação de funções é imposta no TaskService. --}}
@can('tasks.approve', $task->endpoint)
    @if ($task->state === \App\Enums\TaskState::PendingApproval && (int) $task->created_by !== (int) $user->id)
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('tasks.approve', $task->uuid) }}">@csrf
                <button class="rounded bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Aprovar</button>
            </form>
            <form method="POST" action="{{ route('tasks.reject', $task->uuid) }}" class="flex flex-wrap items-center gap-2">@csrf
                <label class="sr-only" for="reason-{{ $task->uuid }}">Motivo da rejeição (opcional)</label>
                <input id="reason-{{ $task->uuid }}" name="reason" maxlength="255" placeholder="Motivo (opcional)" class="w-48 rounded border border-slate-300 px-2 py-1 text-sm">
                <button class="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100">Rejeitar</button>
            </form>
        </div>
    @endif
@endcan
