<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI provider that will be used to analyze
    | security incidents and execute prompts. You may change this in your .env.
    | Supported providers: "gemini", "anthropic", "openai", "mock".
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Global Proxy & Timeout Options
    |--------------------------------------------------------------------------
    |
    | If your network requires a proxy to access AI provider APIs (e.g. Google,
    | Anthropic, OpenAI), you may configure the proxy URL here.
    | Example: "http://127.0.0.1:7890" or "socks5://127.0.0.1:1080".
    |
    */

    'proxy' => env('AI_HTTP_PROXY', env('HTTPS_PROXY', env('HTTP_PROXY'))),
    'timeout' => (int) env('AI_REQUEST_TIMEOUT', 90),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | AI Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the settings for each AI provider, including API
    | keys, model names, endpoints, proxy, and timeout limits.
    |
    */

    'providers' => [

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'timeout' => (int) env('AI_REQUEST_TIMEOUT', 90),
            'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 15),
            'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
            'proxy' => env('AI_HTTP_PROXY', env('HTTPS_PROXY', env('HTTP_PROXY'))),
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'timeout' => (int) env('AI_REQUEST_TIMEOUT', 30),
            'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'proxy' => env('AI_HTTP_PROXY', env('HTTPS_PROXY', env('HTTP_PROXY'))),
        ],

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('AI_REQUEST_TIMEOUT', 30),
            'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
            'proxy' => env('AI_HTTP_PROXY', env('HTTPS_PROXY', env('HTTP_PROXY'))),
        ],

        'mock' => [
            'driver' => 'mock',
            'model' => 'mock-security-agent-v1',
        ],

    ],

];
