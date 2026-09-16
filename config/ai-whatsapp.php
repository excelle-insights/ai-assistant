<?php

return [
    'table_prefix' => $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp',
    'openai' => [
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
        'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
        'timeout' => (int)($_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30),
        'whisper_model' => $_ENV['OPENAI_WHISPER_MODEL'] ?? 'whisper-1',
    ],
    'rate_limit' => [
        'per_hour' => (int)($_ENV['AI_RATE_LIMIT_PER_HOUR'] ?? 60),
    ],
    'auto_reply' => [
        'enabled' => ($_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true') !== 'false',
        'outside_session_use_template' => true,
        'fallback_template_id' => (int)($_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0),
    ],
];
