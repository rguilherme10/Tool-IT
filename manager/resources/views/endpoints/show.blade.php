@extends('layouts.app')
@section('title', $endpoint->hostname)

@section('content')
<div class="mb-4 flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-semibold">{{ $endpoint->hostname }}</h1>
    <span class="rounded-full px-2 py-0.5 text-xs {{ $online ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $online ? 'Online' : 'Sem contato' }}</span>
</div>

<nav aria-label="Seções do computador" class="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('endpoints.show', ['uuid' => $endpoint->uuid, 'tab' => $key]) }}"
           @if ($tab === $key) aria-current="page" @endif
           class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-slate-900 font-medium text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
    @endforeach
</nav>

@include('endpoints.tabs.'.$tab)
@endsection
