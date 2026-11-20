<?php

return [
    'indexable' => (bool) env('CV_INDEXABLE', false),
    'registration' => (bool) env('CV_REGISTRATION', in_array(env('APP_ENV'), ['local', 'testing'], true)),
    'openai_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    'ollama_model' => env('OLLAMA_MODEL', 'qwen3:8b'),
    'ollama_enabled' => (bool) env('OLLAMA_ENABLED', env('APP_ENV') === 'local'),
    'browser_executable' => env('CV_BROWSER_EXECUTABLE'),
    'browser_node' => env('CV_BROWSER_NODE', 'node'),
    'france_travail_id' => env('API_France_Travail'),
    'france_travail_secret' => env('API_France_Travail_secret'),
];
