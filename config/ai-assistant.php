<?php

return [
    'table_prefix' => $_ENV['AI_ASSISTANT_TABLE_PREFIX'] ?? $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_assistant',
    // Canonical LLM config — any OpenAI-compatible provider (see Support\LlmFactory).
    'llm' => [
        'provider' => $_ENV['LLM_PROVIDER'] ?? 'openai',
        'api_key' => $_ENV['LLM_API_KEY'] ?? $_ENV['OPENAI_API_KEY'] ?? '',
        'base_url' => $_ENV['LLM_BASE_URL'] ?? $_ENV['OPENAI_BASE_URL'] ?? null,
        'model' => $_ENV['LLM_MODEL'] ?? $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
        'embedding_model' => $_ENV['LLM_EMBEDDING_MODEL'] ?? 'text-embedding-3-small',
        'timeout' => (int)($_ENV['LLM_REQUEST_TIMEOUT'] ?? $_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30),
        'whisper_model' => $_ENV['OPENAI_WHISPER_MODEL'] ?? 'whisper-1',
    ],
    // Legacy alias — prefer 'llm' above.
    'openai' => [
        'api_key' => $_ENV['LLM_API_KEY'] ?? $_ENV['OPENAI_API_KEY'] ?? '',
        'model' => $_ENV['LLM_MODEL'] ?? $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
        'timeout' => (int)($_ENV['LLM_REQUEST_TIMEOUT'] ?? $_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30),
        'whisper_model' => $_ENV['OPENAI_WHISPER_MODEL'] ?? 'whisper-1',
    ],
    'rate_limit' => [
        'per_hour' => (int)($_ENV['AI_RATE_LIMIT_PER_HOUR'] ?? 60),
    ],
    'auto_reply' => [
        'enabled' => ($_ENV['AI_ASSISTANT_AUTO_REPLY'] ?? $_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true') !== 'false',
        'outside_session_use_template' => true,
        'fallback_template_id' => (int)($_ENV['AI_ASSISTANT_FALLBACK_TEMPLATE'] ?? $_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0),
    ],
];
