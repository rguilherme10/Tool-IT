@php
    $riskClass = ['low' => 'bg-slate-100 text-slate-700', 'medium' => 'bg-amber-100 text-amber-800', 'high' => 'bg-red-100 text-red-800'][$task->risk->value];
@endphp
<span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $riskClass }}">{{ $task->risk->label() }}</span>
