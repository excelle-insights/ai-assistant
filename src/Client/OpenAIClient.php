<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Client;

use GuzzleHttp\Client;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\LlmFactory;

/**
 * Chat + embeddings over any OpenAI-compatible HTTP API.
 *
 * Works with: OpenAI, OpenRouter, Together, vLLM, LM Studio, Ollama
 * (Ollama: chat yes via /api/chat OpenAI-compat endpoint; embeddings yes).
 * Only the base URL + key + model change (see Support\LlmFactory).
 */
class OpenAIClient implements LlmClientInterface
{
    private Client $http;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        ?string $apiKey = null,
        ?int $timeout = null,
        ?Client $http = null,
        ?string $baseUrl = null
    ) {
        EnvLoader::load();
        $this->apiKey = $apiKey
            ?? ($_ENV['LLM_API_KEY'] ?? $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');
        $this->timeout = $timeout
            ?? (int)($_ENV['LLM_REQUEST_TIMEOUT'] ?? $_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30);
        $this->baseUrl = rtrim(
            $baseUrl ?? ($_ENV['LLM_BASE_URL'] ?? $_ENV['OPENAI_BASE_URL'] ?? LlmFactory::defaultBaseUrl()),
            '/'
        );
        $this->http = $http ?? new Client(['timeout' => $this->timeout]);

        if ($this->apiKey === '' && !$this->allowsEmptyKey()) {
            throw new \RuntimeException('LLM_API_KEY (or OPENAI_API_KEY) not set in host .env');
        }
    }

    /** Local servers (Ollama/LM Studio/vLLM) commonly need no key. */
    private function allowsEmptyKey(): bool
    {
        $host = strtolower(parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local');
    }

    /**
     * Chat completion — same shape as ExcelleCore\AI\OpenAIClient::chat().
     * Returns ['content'=>string, 'usage'=>['prompt_tokens'=>int,'completion_tokens'=>int]]
     */
    public function chat(array $messages, string $model = '', ?float $temperature = null): array
    {
        if ($model === '') {
            $model = $_ENV['LLM_MODEL'] ?? $_ENV['OPENAI_MODEL'] ?? 'gpt-4o';
        }
        $json = [
            'model' => $model,
            'messages' => $messages,
        ];
        if ($temperature !== null) {
            $json['temperature'] = $temperature;
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        // OpenRouter recommends identifying the app; harmless elsewhere.
        if (!empty($_ENV['LLM_APP_URL'] ?? '')) {
            $headers['HTTP-Referer'] = (string) $_ENV['LLM_APP_URL'];
        }
        $res = $this->http->post($this->baseUrl . '/chat/completions', [
            'headers' => $headers,
            'json' => $json,
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        return ['content' => $content, 'usage' => $usage, 'raw' => $data];
    }

    public function embeddings(string $input, string $model = ''): array
    {
        if ($model === '') {
            $model = $_ENV['LLM_EMBEDDING_MODEL'] ?? 'text-embedding-3-small';
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $res = $this->http->post($this->baseUrl . '/embeddings', [
            'headers' => $headers,
            'json' => [
                'model' => $model,
                'input' => $input,
            ],
        ]);

        $data = json_decode((string)$res->getBody(), true);
        return $data['data'][0]['embedding'] ?? [];
    }
}
