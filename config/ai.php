<?php

return [
    // Opt in explicitly; loading the Action Center never invokes a provider.
    'enabled' => (bool) env('AI_EXPLANATIONS_ENABLED', false),
    'provider' => env('AI_EXPLANATIONS_PROVIDER', 'groq'),
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    ],
];
