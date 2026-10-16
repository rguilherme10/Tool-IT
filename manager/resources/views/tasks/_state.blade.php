@php
    $stateClass = [
        'pending_approval' => 'bg-amber-100 text-amber-800',
        'available' => 'bg-sky-100 text-sky-800',
        'running' => 'bg-indigo-100 text-indigo-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-slate-200 text-slate-700',
        'expired' => 'bg-slate-200 text-slate-700',
    ][$task->state->value];
@endphp
<span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $stateClass }}">{{ $task->state->label() }}</span>
