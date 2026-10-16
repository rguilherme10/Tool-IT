<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Toolit') — Toolit</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @auth
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('dashboard') }}" class="font-semibold">Toolit</a>
            <nav class="flex flex-wrap gap-4 text-sm text-slate-600">
                <a href="{{ route('dashboard') }}" class="hover:text-slate-900">Painel</a>
                @can('inventory.view')
                    <a href="{{ route('endpoints.index') }}" class="hover:text-slate-900">Computadores</a>
                @endcan
                @can('alerts.view')
                    <a href="{{ route('alerts.index') }}" class="hover:text-slate-900">Alertas</a>
                @endcan
                @can('tasks.approve')
                    <a href="{{ route('tasks.approvals') }}" class="hover:text-slate-900">Aprovações</a>
                @endcan
                @can('settings.manage')
                    <a href="{{ route('alert-rules.index') }}" class="hover:text-slate-900">Regras de alerta</a>
                    <a href="{{ route('groups.index') }}" class="hover:text-slate-900">Grupos</a>
                    <a href="{{ route('file-policies.index') }}" class="hover:text-slate-900">Política de arquivos</a>
                @endcan
                @can('users.manage')
                    <a href="{{ route('users.index') }}" class="hover:text-slate-900">Usuários</a>
                @endcan
                @can('audit.view')
                    <a href="{{ route('audit.index') }}" class="hover:text-slate-900">Auditoria</a>
                @endcan
                @can('enrollment.create')
                    <a href="{{ route('enrollment.create') }}" class="hover:text-slate-900">Novo agente</a>
                @endcan
                <a href="{{ route('security.show') }}" class="hover:text-slate-900">Segurança</a>
            </nav>
            <div class="ml-auto flex items-center gap-3 text-sm text-slate-600">
                <span>{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="rounded border border-slate-300 px-2 py-1 hover:bg-slate-100">Sair</button>
                </form>
            </div>
        </div>
    </header>
    @endauth

    <main class="mx-auto max-w-6xl px-4 py-6">
        @if (session('status'))
            <div role="status" class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
