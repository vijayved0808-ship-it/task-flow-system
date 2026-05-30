<?php

return [
    'whatsapp' => [
        'token'           => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token'    => env('WHATSAPP_VERIFY_TOKEN', 'taskflow-secret-123'),
        'api_version'     => env('WHATSAPP_API_VERSION', 'v19.0'),
    ],

    'anthropic' => [
        'api_key'    => env('ANTHROPIC_API_KEY'),
        'model'      => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1000),
    ],
];
