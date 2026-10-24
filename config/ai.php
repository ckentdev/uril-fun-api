<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported providers: "mock" (no credentials, deterministic replies) and
    | "openai" (OpenAI or any OpenAI-compatible chat completions API).
    | Keys stay on the backend and are never returned to the frontend.
    |
    */

    'provider' => env('AI_PROVIDER', 'mock'),

    'api_key' => env('AI_API_KEY'),

    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),

    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    // RepoHive Cloud AI (AI_PROVIDER=repohive). Uses AI_API_KEY as the Bearer key.
    'repohive' => [
        'base_url' => env('AI_REPOHIVE_URL', 'https://apicloud.repohive.com/api'),
    ],

    // Server-wide fallback knowledge. A user's own text saved on the AI Knowledge page takes precedence.
    'knowledge' => env('AI_KNOWLEDGE'),

    // Max length of the knowledge a user can save.
    'knowledge_max_chars' => (int) env('AI_KNOWLEDGE_MAX_CHARS', 20000),

    'timeout' => (int) env('AI_TIMEOUT', 30),

    'system_prompt' => env('AI_SYSTEM_PROMPT', 'You are a helpful assistant inside the AI Integration Hub demo.'),

    'history_limit' => (int) env('AI_HISTORY_LIMIT', 20),

    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 4000),

];
