<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

use ExcelleInsights\AiAssistant\Client\OpenAIClient;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;

/**
 * Builds the LLM client from env — the provider is a deployment choice.
 *
 *   LLM_PROVIDER=openai          # default; api.openai.com (or LLM_BASE_URL)
 *   LLM_PROVIDER=openrouter      # https://openrouter.ai/api/v1
 *   LLM_PROVIDER=together        # https://api.together.xyz/v1
 *   LLM_PROVIDER=ollama          # http://localhost:11434/v1 (no key needed)
 *   LLM_PROVIDER=vllm|lmstudio|custom  # LLM_BASE_URL required
 *
 * Keys (each falls back to the OPENAI_* equivalent):
 *   LLM_API_KEY, LLM_BASE_URL, LLM_MODEL, LLM_EMBEDDING_MODEL,
 *   LLM_REQUEST_TIMEOUT, OPENAI_MODEL, OPENAI_API_KEY, ...
 *
 * Native (non-OpenAI-compatible) providers: implement LlmClientInterface
 * and extend provider() with your case. Anthropic/Gemini users can also go
 * through OpenRouter with LLM_PROVIDER=openrouter today.
 */
final class LlmFactory
{
    private const COMPAT_BASE_URLS = [
        'openai'    => 'https://api.openai.com/v1',
        'openrouter'=> 'https://openrouter.ai/api/v1',
        'together'  => 'https://api.together.xyz/v1',
        'ollama'    => 'http://localhost:11434/v1',
    ];

    public static function provider(): string
    {
        return strtolower(trim((string) ($_ENV['LLM_PROVIDER'] ?? 'openai')));
    }

    /** Default base URL for a provider (env overrides win in make()). */
    public static function defaultBaseUrl(?string $provider = null): string
    {
        $provider = strtolower(trim($provider ?? self::provider()));
        return self::COMPAT_BASE_URLS[$provider] ?? self::COMPAT_BASE_URLS['openai'];
    }

    public static function make(?string $provider = null): LlmClientInterface
    {
        EnvLoader::load();
        $provider = strtolower(trim($provider ?? self::provider()));

        $baseUrl = $_ENV['LLM_BASE_URL'] ?? $_ENV['OPENAI_BASE_URL'] ?? null;
        if ($baseUrl === null && isset(self::COMPAT_BASE_URLS[$provider])) {
            $baseUrl = self::COMPAT_BASE_URLS[$provider];
        }

        switch ($provider) {
            case 'openai':
            case 'openrouter':
            case 'together':
            case 'ollama':
            case 'vllm':
            case 'lmstudio':
            case 'custom':
            case 'openai-compatible':
                return new OpenAIClient(baseUrl: $baseUrl);
            default:
                throw new \InvalidArgumentException(
                    'Unknown LLM_PROVIDER "' . $provider . '". '
                    . 'Use openai|openrouter|together|ollama|vllm|lmstudio|custom, '
                    . 'or implement LlmClientInterface for a native client.'
                );
        }
    }

    /** Default chat model for the configured provider. */
    public static function defaultModel(): string
    {
        EnvLoader::load();
        $explicit = $_ENV['LLM_MODEL'] ?? $_ENV['OPENAI_MODEL'] ?? '';
        if ($explicit !== '') {
            return $explicit;
        }
        return match (self::provider()) {
            'ollama'   => 'llama3.1',
            'openrouter' => 'openai/gpt-4o',
            default    => 'gpt-4o',
        };
    }
}
