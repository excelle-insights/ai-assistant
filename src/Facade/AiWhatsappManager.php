<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Facade;

use PDO;
use ExcelleInsights\AiWhatsapp\Client\OpenAIClient;
use ExcelleInsights\AiWhatsapp\Contracts\SystemContextProviderInterface;
use ExcelleInsights\AiWhatsapp\Services\AiWhatsappService;
use ExcelleInsights\AiWhatsapp\Services\KnowledgeService;
use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

class AiWhatsappManager
{
    private PDO $pdo;
    private ?SystemContextProviderInterface $contextProvider;
    private OpenAIClient $openAI;
    private KnowledgeService $knowledge;

    public function __construct(
        ?PDO $pdo = null,
        ?SystemContextProviderInterface $contextProvider = null,
        ?OpenAIClient $openAI = null,
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

    public function getService(): AiWhatsappService
    {
        return new AiWhatsappService($this->pdo, $this->contextProvider, $this->openAI, $this->knowledge);
    }

    public function getKnowledgeService(): KnowledgeService
    {
        return $this->knowledge;
    }
}
