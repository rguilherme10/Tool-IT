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
    ],

    'audit' => [
        'retention_months' => 24,
    ],
];
