<?php

declare(strict_types=1);

namespace ExcelleInsights\AiWhatsapp\Services;

use PDO;
use ExcelleInsights\AiWhatsapp\Client\OpenAIClient;
use ExcelleInsights\AiWhatsapp\Contracts\SystemContextProviderInterface;
use ExcelleInsights\AiWhatsapp\Support\EnvLoader;

class AiWhatsappService
{
    private string $prefix;
    private string $waPrefix;

    public function __construct(
        private ?PDO $pdo = null,
        private ?SystemContextProviderInterface $contextProvider = null,
        private ?OpenAIClient $openAI = null,
        private ?KnowledgeService $knowledge = null,
    ) {
        EnvLoader::load();
        $this->prefix = $_ENV['AI_WHATSAPP_TABLE_PREFIX'] ?? 'ai_whatsapp';
        $this->waPrefix = $_ENV['WHATSAPP_TABLE_PREFIX'] ?? 'whatsapp';
        if (!$pdo) {
            $dsn = $_ENV['DB_DSN'] ?? null;
            $user = $_ENV['DB_USER'] ?? null;
            $pass = $_ENV['DB_PASSWORD'] ?? null;
            if (!$dsn) throw new \RuntimeException('DB_DSN not set');
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        $this->pdo = $pdo;
        if (!$openAI) {
            try { $this->openAI = new OpenAIClient(); } catch (\Throwable $e) { $this->openAI = null; }
        }
        $this->knowledge = $knowledge ?? new KnowledgeService($this->pdo, $this->openAI);
    }

    /**
     * Called by host after WhatsappService::processWebhookPayload() — $results is that return value.
     * $results = [['type'=>'message','wa_message_id'=>..., 'conversation_id'=>...], ...]
     */
    public function onInboundMessages(array $results): void
    {
        foreach ($results as $r) {
            if (($r['type'] ?? '') !== 'message') continue;
            $convId = (int)($r['conversation_id'] ?? 0);
            $waMsgId = $r['wa_message_id'] ?? '';
            if (!$convId || !$waMsgId) continue;
            try { $this->onInboundMessage($convId, $waMsgId); } catch (\Throwable $e) { error_log('AiWhatsapp onInboundMessage failed conv '.$convId.': '.$e->getMessage()); }
        }
    }

    public function onInboundMessage(int $conversationId, string $waMessageId): void
    {
        $autoEnabled = $_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true';
        if ($autoEnabled === 'false' || $autoEnabled === '0') return;

        // Load conversation + last inbound
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_conversations WHERE conversation_id = :cid LIMIT 1");
        $stmt->execute(['cid' => $conversationId]);
        $conv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$conv) return;

        if ($this->contextProvider && !$this->contextProvider->canAutoReply($conversationId)) return;

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_messages WHERE wa_message_id = :wamid AND conversation_id = :cid LIMIT 1");
        $stmt->execute(['wamid' => $waMessageId, 'cid' => $conversationId]);
        $msg = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$msg) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_messages WHERE conversation_id = :cid ORDER BY id DESC LIMIT 1");
            $stmt->execute(['cid' => $conversationId]);
            $msg = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        if (!$msg || ($msg['direction'] ?? '') !== 'inbound') return;

        $messageBody = trim((string)($msg['message_body'] ?? ''));
        if ($messageBody === '') return;

        // Resolve company_id via conversation or via context provider
        $companyId = (int)($conv['company_id'] ?? 0);
        if (!$companyId && $this->contextProvider) {
            // Fallback: try to infer via provider or default 1
            $companyId = 1;
        }
        if (!$companyId) $companyId = 1;

        // Knowledge + company context
        $chunks = $this->knowledge->search($companyId, $messageBody, 5);
        $companyCtx = '';
        $customerCtx = '';
        if ($this->contextProvider) {
            $companyCtx = json_encode($this->contextProvider->getCompanyContext($companyId));
            $customerCtx = json_encode($this->contextProvider->getCustomerContext($conversationId));
        }

        $system = "You are an AI assistant for ".($companyCtx ?: "a business").". Use only provided KNOWLEDGE. If unknown, say you will connect to staff. Be concise, friendly, no markdown fences. Reply in same language as customer.";
        $user = "";
        if (!empty($chunks)) $user .= "KNOWLEDGE:\n" . implode("\n---\n", $chunks) . "\n\n";
        if ($companyCtx) $user .= "COMPANY:\n$companyCtx\n\n";
        if ($customerCtx) $user .= "CUSTOMER:\n$customerCtx\n\n";
        $user .= "MESSAGE:\n$messageBody\n\nReply:";

        if (!$this->openAI) {
            error_log('AiWhatsapp: OpenAI not configured, skipping reply');
            return;
        }

        $model = $_ENV['OPENAI_MODEL'] ?? 'gpt-4o';
        $res = $this->openAI->chat([['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]], $model);
        $reply = trim((string)($res['content'] ?? ''));
        if ($reply === '') return;

        // Enqueue or send directly — check 24h session
        $sessionExpires = $conv['session_expires_at'] ?? null;
        $inSession = $sessionExpires && strtotime($sessionExpires) > time();

        if ($inSession) {
            // Direct send via whatsapp API (host's WhatsappApi or direct Graph)
            $this->sendText($conversationId, $conv['contact_phone'], $reply);
        } else {
            // Outside session → queue template fallback if configured
            $tplId = (int)($_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0);
            if ($tplId) {
                $this->pdo->prepare("INSERT INTO {$this->waPrefix}_queue (lead_id, contact_phone, contact_name, template_id, placeholders_data, status, author_id, created_at) VALUES (0, :phone, :name, :tid, :ph, 'pending', 0, NOW())")
                    ->execute(['phone' => $conv['contact_phone'], 'name' => $conv['contact_name'] ?? null, 'tid' => $tplId, 'ph' => json_encode([$reply])]);
            } else {
                // Still try direct — will fail if window closed, but log
                $this->sendText($conversationId, $conv['contact_phone'], $reply);
            }
        }

        // Log session
        $this->storeSession($conversationId, $companyId, $messageBody, $reply);
    }

    private function sendText(int $conversationId, string $to, string $body): void
    {
        // Prefer host's WhatsappApi if available
        if (class_exists(\Packages\Integrations\Whatsapp\WhatsappApi::class)) {
            \Packages\Integrations\Whatsapp\WhatsappApi::sendTextMessage($to, $body);
            // Also insert outbound message for UI (like WhatsappService::sendMessage)
            $now = date('Y-m-d H:i:s');
            $this->pdo->prepare("INSERT INTO {$this->waPrefix}_messages (conversation_id, direction, message_body, message_type, delivery_status, sent_at, author_id) VALUES (:cid, 'outbound', :body, 'text', 'sent', :now, 0)")
                ->execute(['cid' => $conversationId, 'body' => $body, 'now' => $now]);
            $this->pdo->prepare("UPDATE {$this->waPrefix}_conversations SET last_message_at = :now WHERE conversation_id = :cid")->execute(['now' => $now, 'cid' => $conversationId]);
            return;
        }
        // Fallback: direct Graph call
        $this->pdo->prepare("INSERT INTO {$this->prefix}_queue (conversation_id, wa_message_id, status, payload, created_at) VALUES (:cid, :wamid, 'pending', :payload, NOW())")
            ->execute(['cid' => $conversationId, 'wamid' => uniqid('ai_'), 'payload' => json_encode(['to'=>$to,'body'=>$body])]);
    }

    private function storeSession(int $conversationId, int $companyId, string $in, string $out): void
    {
        $table = $this->prefix . '_sessions';
        $history = json_encode([['role'=>'user','content'=>$in],['role'=>'assistant','content'=>$out]]);
        $this->pdo->prepare("INSERT INTO {$table} (conversation_id, company_id, history_json, expires_at, created_at, updated_at) VALUES (:cid, :comp, :hist, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), NOW()) ON DUPLICATE KEY UPDATE history_json = :hist2, expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR), updated_at = NOW()")
            ->execute(['cid'=>$conversationId,'comp'=>$companyId,'hist'=>$history,'hist2'=>$history]);
    }
}
