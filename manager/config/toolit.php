<?php

return [

    /*
    | Parâmetros do protocolo do Agent (docs/api/agent-api-v1.md). Padrões conservadores;
    | valores operacionais por ambiente em .env, valores por política em system_settings.
    */
    'agent' => [
        'protocol' => 'v1',
        'min_version' => env('TOOLIT_AGENT_MIN_VERSION', '0.1.0'),
        'timestamp_skew_seconds' => (int) env('TOOLIT_AGENT_SKEW', 120),
        'nonce_ttl_seconds' => (int) env('TOOLIT_AGENT_NONCE_TTL', 300),
        'previous_key_grace_seconds' => 300,
        'rate_limit_per_minute' => (int) env('TOOLIT_AGENT_RATE_LIMIT', 120),
        'max_body_bytes' => 1048576,
        'enrollment_ttl_hours' => 24,

        // Entregue ao Agent no enroll; o Agent aplica limites mínimos próprios.
        'config' => [
            'heartbeat_seconds' => 60,
            'metrics_seconds' => 60,
            'inventory_hours' => 24,
            'max_payload_bytes' => 1048576,
        ],
    ],

    'security' => [
        'require_mfa_for_admins' => (bool) env('TOOLIT_REQUIRE_MFA_ADMINS', true),
    ],

    'retention' => [
        'metrics_days' => (int) env('TOOLIT_RETENTION_METRICS_DAYS', 7),
        'alerts_days' => (int) env('TOOLIT_RETENTION_ALERTS_DAYS', 365),
    ],

    'alerts' => [
        // Maior intervalo aceitável entre amostras (e idade máxima da última) para concluir que uma condição
        // "persiste": ≈ 2,5 × o intervalo padrão de métricas (60 s). Menos que isso gera alertas por lacunas.
        'gap_seconds' => (int) env('TOOLIT_ALERTS_GAP', 150),
    ],

    'tasks' => [
        // Tarefa não pega por nenhum Agent expira após este tempo.
        'pending_ttl_minutes' => (int) env('TOOLIT_TASKS_PENDING_TTL', 30),

        // Padrões; a política vigente fica em system_settings (tasks.service_allowlist / tasks.service_protected),
        // editável só por quem tem settings.manage.
        'service_allowlist' => ['Spooler', 'W32Time', 'BITS', 'wuauserv'],
        'service_protected' => [
            'RpcSs', 'DcomLaunch', 'LSM', 'SamSs', 'EventLog', 'BFE', 'mpssvc', 'WinDefend', 'CryptSvc', 'Winmgmt', 'ToolitAgent',
        ],
    ],

    'transfers' => [
        // Temporários e blobs de transferências são apagados após este prazo (os metadados ficam).
        'retention_days' => (int) env('TOOLIT_TRANSFERS_RETENTION_DAYS', 7),
        // Teto padrão de uma transferência (a política do grupo pode reduzir); 100 MiB.
        'max_bytes' => 104857600,
        // Tamanho do chunk do upload pelo navegador (1 MiB) e do trânsito Agent <-> Manager (2 MiB).
        'browser_chunk_bytes' => 1048576,
        'agent_chunk_bytes' => 2097152,
        // Limite das rotas de transferência do Agent, por instalação (separado de toolit.agent.rate_limit_per_minute).
        'rate_limit_per_minute' => (int) env('TOOLIT_TRANSFER_RATE_LIMIT', 600),
    ],

    'audit' => [
        'retention_months' => 24,
    ],
];
