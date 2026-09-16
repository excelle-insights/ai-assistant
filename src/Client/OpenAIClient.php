<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Client;

use GuzzleHttp\Client;
use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

class OpenAIClient
{
    private Client $http;
    private string $apiKey;
    private int $timeout;

    public function __construct(?string $apiKey = null, ?int $timeout = null, ?Client $http = null)
    {
        EnvLoader::load();
        $this->apiKey = $apiKey ?? ($_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '');
        $this->timeout = $timeout ?? (int)($_ENV['OPENAI_REQUEST_TIMEOUT'] ?? 30);
        $this->http = $http ?? new Client(['timeout' => $this->timeout]);

        if ($this->apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY not set in host .env');
        }
    }

    /**
     * Chat completion — same signature as ExcelleCore\AI\OpenAIClient::chat() used in AiController.php:86
     * Returns ['content'=>string, 'usage'=>['prompt_tokens'=>int,'completion_tokens'=>int]]
     */
    public function chat(array $messages, string $model = 'gpt-4o'): array
    {
        $res = $this->http->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
            ],
        ]);

        $data = json_decode((string)$res->getBody(), true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        return ['content' => $content, 'usage' => $usage, 'raw' => $data];
    }

    public function embeddings(string $input, string $model = 'text-embedding-3-small'): array
    {
        $res = $this->http->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'input' => $input,
            ],
        ]);

        $data = json_decode((string)$res->getBody(), true);
        return $data['data'][0]['embedding'] ?? [];
    }
}
