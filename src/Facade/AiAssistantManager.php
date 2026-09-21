<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Facade;

use PDO;
use ExcelleInsights\AiAssistant\Client\OpenAIClient;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\SystemContextProviderInterface;
use ExcelleInsights\AiAssistant\Services\AiAssistantService;
use ExcelleInsights\AiAssistant\Services\KnowledgeService;
use ExcelleInsights\AiAssistant\Support\EnvLoader;

class AiAssistantManager
{
    private PDO $pdo;
    private ?SystemContextProviderInterface $contextProvider;
    private LlmClientInterface $openAI;
    private KnowledgeService $knowledge;

    public function __construct(
        ?PDO $pdo = null,
        ?SystemContextProviderInterface $contextProvider = null,
        ?LlmClientInterface $openAI = null,
        ?string $envRoot = null
    ) {
        EnvLoader::load($envRoot);

        if (!$pdo) {
            $dsn = $_ENV['DB_DSN'] ?? null;
            $user = $_ENV['DB_USER'] ?? null;
            $pass = $_ENV['DB_PASSWORD'] ?? null;
            if (!$dsn) throw new \RuntimeException('DB_DSN not set');
            $pdo = new PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        $this->pdo = $pdo;
        $this->contextProvider = $contextProvider;
        $this->openAI = $openAI ?? new OpenAIClient();
        $this->knowledge = new KnowledgeService($this->pdo, $this->openAI);
    }

    public function getService(): AiAssistantService
    {
        return new AiAssistantService($this->pdo, $this->contextProvider, $this->openAI, $this->knowledge);
    }

    public function getKnowledgeService(): KnowledgeService
    {
        return $this->knowledge;
    }
}
