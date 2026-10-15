@extends('layouts.app')
@section('title', 'Painel')

@section('content')
<h1 class="mb-4 text-xl font-semibold">Painel</h1>

<section aria-label="Resumo dos computadores" class="grid grid-cols-2 gap-3 md:grid-cols-4">
    @foreach ([['Computadores', $total], ['Online', $online], ['Sem contato', $offline], ['Nunca vistos', $neverSeen]] as [$label, $value])
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-sm text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
        </div>
    @endforeach
</section>
<p class="mt-2 text-xs text-slate-500">Online = contato nos últimos {{ intdiv(\App\Http\Controllers\Web\DashboardController::ONLINE_WINDOW_SECONDS, 60) }} minutos. Valores calculados somente sobre os computadores que você pode ver.</p>

@if ($alerts !== null)
<section aria-label="Alertas ativos" class="mt-4 grid grid-cols-3 gap-3">
    @foreach ([['Alertas críticos', $alerts['critical'], 'text-red-700'], ['Alertas de atenção', $alerts['warning'], 'text-amber-700'], ['Informativos', $alerts['info'], 'text-sky-700']] as [$label, $value, $color])
        <a href="{{ route('alerts.index') }}" class="rounded-lg border border-slate-200 bg-white p-4 hover:bg-slate-50">
            <div class="text-sm text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-semibold {{ $value > 0 ? $color : '' }}">{{ $value }}</div>
        </a>
    @endforeach
</section>
@endif

<section class="mt-8">
    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Estado dos módulos</h2>
    <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
        @foreach ([
            ['Cadastro de agentes, heartbeat e revogação', true],
            ['Inventário de hardware e software', false],
            ['Métricas e alertas de CPU, memória, disco, comunicação e versão', true],
            ['Alertas de serviços Windows e eventos de erro', false],
            ['Tarefas remotas, processos e serviços', false],
            ['Transferência de arquivos', false],
            ['Suporte remoto (visualização de tela)', false],
            ['Versões e atualização de agentes', false],
        ] as [$name, $done])
            <li class="flex items-center justify-between px-4 py-2">
                <span>{{ $name }}</span>
                <span class="{{ $done ? 'text-emerald-700' : 'text-slate-500' }}">{{ $done ? 'Disponível' : 'Não implementado' }}</span>
            </li>
        @endforeach
    </ul>
</section>
@endsection
