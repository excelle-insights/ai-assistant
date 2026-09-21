<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Any chat LLM provider (OpenAI, OpenRouter, Together, Ollama, vLLM,
 * LM Studio, or a native Anthropic/Gemini adapter).
 *
 * The package only talks to this interface — never to a concrete client —
 * so the model provider is a deployment choice, not a code dependency.
 */
interface LlmClientInterface
{
    /**
     * Chat completion.
     * $messages: [['role' => 'system|user|assistant', 'content' => string], ...]
     * Returns ['content' => string, 'usage' => [...], 'raw' => mixed].
     * Empty $model = provider default (see LlmFactory::defaultModel()).
     */
    public function chat(array $messages, string $model = '', ?float $temperature = null): array;

    /**
     * Text embedding vector. Returns [] when the provider has no
     * embeddings endpoint (callers treat [] as "no embedding").
     */
    public function embeddings(string $input, string $model = ''): array;
}
