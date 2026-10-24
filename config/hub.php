<?php

return [

    /*
    | Per-minute rate limits for the demo endpoints (see AppServiceProvider).
    */
    'limits' => [
        'email' => (int) env('RATE_LIMIT_EMAIL', 3),
        'ai' => (int) env('RATE_LIMIT_AI', 20),
        'chat' => (int) env('RATE_LIMIT_CHAT', 20),
    ],

    'email' => [
        // Max demo emails per user per rolling 24h.
        'daily_limit' => (int) env('EMAIL_DAILY_LIMIT', 20),
        // Optional comma-separated recipient domain allowlist (empty = any domain).
        'allowed_domains' => env('EMAIL_ALLOWED_DOMAINS', ''),
    ],

    /*
    | How long (days) email/integration logs are kept before `hub:prune-logs` removes them.
    */
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 30),

];
