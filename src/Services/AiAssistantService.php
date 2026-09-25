<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use ExcelleInsights\AiAssistant\Client\OpenAIClient;
use ExcelleInsights\AiAssistant\Contracts\BookingHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Contracts\SystemContextProviderInterface;
use ExcelleInsights\AiAssistant\Support\EnvLoader;
use ExcelleInsights\AiAssistant\Support\LlmFactory;
use ExcelleInsights\AiAssistant\Support\TablePrefix;

class AiAssistantService
{
    private string $prefix;
    private string $waPrefix;
    private ?BookingIntentExtractor $bookingExtractor = null;

    public function __construct(
        private ?PDO $pdo = null,
        private ?SystemContextProviderInterface $contextProvider = null,
        private ?LlmClientInterface $openAI = null,
        private ?KnowledgeService $knowledge = null,
        private ?SchemaService $schema = null,
        private ?BookingHandlerInterface $bookingHandler = null,
    ) {
        EnvLoader::load();
        $this->prefix = TablePrefix::get();
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
        $this->schema = $schema ?? new SchemaService($this->pdo);
    }

    /**
     * Register (or replace) the host's booking handler. The handler receives
     * a BookingIntent whenever the assistant detects the customer wants to
     * book — the host decides how to persist it.
     */
    public function setBookingHandler(?BookingHandlerInterface $handler): static
    {
        $this->bookingHandler = $handler;
        return $this;
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
            try {
                $this->onInboundMessage($convId, $waMsgId);
            } catch (\Throwable $e) {
                $this->markStatus($convId, $waMsgId, 'failed', $e->getMessage());
            }
        }
    }

    public function onInboundMessage(int $conversationId, string $waMessageId): void
    {
        $autoEnabled = $_ENV['AI_ASSISTANT_AUTO_REPLY'] ?? $_ENV['AI_WHATSAPP_AUTO_REPLY'] ?? 'true';
        if ($autoEnabled === 'false' || $autoEnabled === '0') {
            $this->markStatus($conversationId, $waMessageId, 'disabled', 'Auto-reply is turned off');
            return;
        }

        // Idempotency: skip if this inbound wa_message_id was already auto-replied.
        // The host webhook can be re-delivered (Meta retries when the synchronous
        // reply is slow to ack), which would otherwise generate duplicate replies.
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM {$this->waPrefix}_messages WHERE wa_message_id = :wamid AND ai_status IS NOT NULL AND ai_status != '' LIMIT 1");
            $stmt->execute(['wamid' => $waMessageId]);
            if ($stmt->fetchColumn()) {
                return;
            }
        } catch (\Throwable $e) {
            // ai_status column may not exist yet on un-migrated hosts — fall through.
        }

        // Load conversation
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_conversations WHERE conversation_id = :cid LIMIT 1");
        $stmt->execute(['cid' => $conversationId]);
        $conv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$conv) {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'Conversation record not found');
            return;
        }

        if ($this->contextProvider && !$this->contextProvider->canAutoReply($conversationId)) {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'Business rules blocked auto-reply');
            return;
        }

        // Load inbound message
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_messages WHERE wa_message_id = :wamid AND conversation_id = :cid LIMIT 1");
        $stmt->execute(['wamid' => $waMessageId, 'cid' => $conversationId]);
        $msg = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$msg) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->waPrefix}_messages WHERE conversation_id = :cid ORDER BY id DESC LIMIT 1");
            $stmt->execute(['cid' => $conversationId]);
            $msg = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        if (!$msg) {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'Inbound message not found');
            return;
        }
        if (($msg['direction'] ?? '') !== 'inbound') {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'Message is not inbound');
            return;
        }

        $messageBody = trim((string)($msg['message_body'] ?? ''));
        if ($messageBody === '') {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'Message had no text');
            return;
        }

        // Resolve company id
        $companyId = (int)($conv['company_id'] ?? 0);
        if (!$companyId) $companyId = $this->resolveCompanyId();

        // Knowledge + self-learned Q&A + schema + reference context
        $chunks = $this->knowledge->search($companyId, $messageBody, 5);
        $training = $this->recentTraining($companyId, $messageBody, 3);
        $domain = $this->schema->domainSummary(40);
        $schemaFields = $this->schema->searchSchema($messageBody, 5);
        $reference = $this->schema->allReference($companyId, 60);
        $ctx = $this->companyContext($companyId);

        $companyName = (string)($ctx['name'] ?? '');
        $servicesTxt = $this->servicesText($ctx['services'] ?? null);

        // Try a safe read-only live query for data questions (pricing, counts, records)
        $queryResult = $this->tryLiveQuery($messageBody);

        $system = "You are a helpful customer-support assistant" . ($companyName ? " for {$companyName}" : "") . "."
            . " LANGUAGE RULE: Reply in the SAME language the customer wrote in. If you cannot determine it, reply in English. Never switch languages; translate any knowledge into the customer's language."
            . " Use the provided KNOWLEDGE, PREVIOUS Q&A, BUSINESS DATA and (when present) the DATABASE QUERY + QUERY RESULT to answer."
            . " When a QUERY RESULT is present, it is authoritative — answer the question directly from it (state the count, price, or records plainly)."
            . " When the customer asks what services/products/items are offered, list them from the AVAILABLE DATA."
            . " When the customer asks about a specific service/product or its price, find it in AVAILABLE DATA by meaning — ignore wording differences, paraphrasing and minor spelling mistakes (e.g. 'changing oil service' means 'Oil Change')."
            . " Only if you have no relevant data at all, politely say you will connect them to a team member."
            . " Be concise and friendly. Do not use markdown.";

        $user = "";
        if (!empty($chunks)) $user .= "KNOWLEDGE:\n" . implode("\n---\n", $chunks) . "\n\n";
        if (!empty($training)) $user .= "PREVIOUS Q&A:\n" . implode("\n", $training) . "\n\n";
        if ($domain !== '') $user .= "BUSINESS DATA (tables): " . $domain . "\n\n";
        if (!empty($reference)) $user .= "AVAILABLE DATA:\n" . implode("\n", $reference) . "\n\n";
        if (!empty($schemaFields)) $user .= "RELEVANT FIELDS:\n" . implode("\n", $schemaFields) . "\n\n";
        if ($queryResult !== null) {
            $user .= "DATABASE QUERY: " . $queryResult['sql'] . "\n";
            $user .= "QUERY RESULT: " . $this->formatRows($queryResult['rows']) . "\n\n";
        }
        $user .= "CUSTOMER MESSAGE:\n{$messageBody}\n\nReply:";

        if (!$this->openAI) {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'OpenAI is not configured');
            return;
        }

        // Conversation memory: carry forward previous turns from the same conversation
        // so follow-ups like "radiator" resolve against what was already discussed.
        $history = $this->loadSession($conversationId);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $turn) {
            $role = $turn['role'] ?? '';
            $content = trim((string)($turn['content'] ?? ''));
            if (($role === 'user' || $role === 'assistant') && $content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        $model = LlmFactory::defaultModel();
        $res = $this->openAI->chat($messages, $model);
        $reply = trim((string)($res['content'] ?? ''));
        if ($reply === '') {
            $this->markStatus($conversationId, $waMessageId, 'failed', 'AI returned an empty response');
            return;
        }

        // Send or queue based on 24h session
        $sessionExpires = $conv['session_expires_at'] ?? null;
        $inSession = $sessionExpires && strtotime($sessionExpires) > time();

        $sentOk = false;
        $sendError = null;
        if ($inSession) {
            [$sentOk, $sendError] = $this->sendText($conversationId, $conv['contact_phone'], $reply);
        } else {
            $tplId = (int)($_ENV['AI_ASSISTANT_FALLBACK_TEMPLATE'] ?? $_ENV['AI_WHATSAPP_FALLBACK_TEMPLATE'] ?? 0);
            if ($tplId) {
                $this->pdo->prepare("INSERT INTO {$this->waPrefix}_queue (lead_id, contact_phone, contact_name, template_id, placeholders_data, status, author_id, created_at) VALUES (0, :phone, :name, :tid, :ph, 'pending', 0, NOW())")
                    ->execute(['phone' => $conv['contact_phone'], 'name' => $conv['contact_name'] ?? null, 'tid' => $tplId, 'ph' => json_encode([$reply])]);
                $sentOk = true;
            } else {
                [$sentOk, $sendError] = $this->sendText($conversationId, $conv['contact_phone'], $reply);
            }
        }

        if ($sentOk) {
            $this->markStatus($conversationId, $waMessageId, 'sent', null);
        } else {
            $this->markStatus($conversationId, $waMessageId, 'send_failed', $sendError ?: 'Failed to send reply');
        }

        // Persist session + self-learning Q&A
        $this->storeSession($conversationId, $companyId, $messageBody, $reply);
        $this->saveTraining($companyId, $messageBody, $reply);

        // Booking hook: hand detected booking requests to the host app.
        // Host failures must never break the reply/webhook — see method.
        $this->maybeHandleBooking($conversationId, $companyId, $conv, $messageBody, $reply);
    }

    /**
     * Detect a booking request in this turn and hand it to the host's
     * BookingHandlerInterface (if registered and enabled).
     *
     * Runs after the reply is sent + session persisted, so it adds no risk
     * to the customer-facing flow. Any host exception is swallowed (logged).
     */
    private function maybeHandleBooking(
        int $conversationId,
        int $companyId,
        array $conv,
        string $messageBody,
        string $reply,
    ): void {
        if ($this->bookingHandler === null) {
            return;
        }
        $enabled = $_ENV['AI_ASSISTANT_BOOKING_HOOK'] ?? $_ENV['AI_WHATSAPP_BOOKING_HOOK'] ?? 'true';
        if ($enabled === 'false' || $enabled === '0') {
            return;
        }
        try {
            if ($this->bookingExtractor === null) {
                $tz = $_ENV['AI_ASSISTANT_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'UTC';
                $this->bookingExtractor = new BookingIntentExtractor($this->openAI, $tz, LlmFactory::defaultModel());
            }
            $intent = $this->bookingExtractor->extract(
                $companyId,
                $conversationId,
                (string) ($conv['contact_phone'] ?? ''),
                ($conv['contact_name'] ?? null) !== null ? (string) $conv['contact_name'] : null,
                $messageBody,
                $reply,
                ['channel' => 'whatsapp', 'conversation_id' => $conversationId],
            );
            if ($intent === null) {
                return;
            }
            $this->bookingHandler->handleBookingIntent($intent);
        } catch (\Throwable $e) {
            error_log('AiAssistantService::maybeHandleBooking: ' . $e->getMessage());
        }
    }

    private function sendText(int $conversationId, string $to, string $body): array
    {
        if (class_exists(\Packages\Integrations\Whatsapp\WhatsappApi::class)) {
            $res = \Packages\Integrations\Whatsapp\WhatsappApi::sendTextMessage($to, $body);
            $now = date('Y-m-d H:i:s');
            $this->pdo->prepare("INSERT INTO {$this->waPrefix}_messages (conversation_id, direction, message_body, message_type, delivery_status, sent_at, author_id) VALUES (:cid, 'outbound', :body, 'text', :status, :now, 0)")
                ->execute(['cid' => $conversationId, 'body' => $body, 'status' => ($res['success'] ?? false) ? 'sent' : 'failed', 'now' => $now]);
            $this->pdo->prepare("UPDATE {$this->waPrefix}_conversations SET last_message_at = :now WHERE conversation_id = :cid")->execute(['now' => $now, 'cid' => $conversationId]);
            if ($res['success'] ?? false) {
                return [true, null];
            }
            $err = $res['error'] ?? (isset($res['data']['error']['message']) ? $res['data']['error']['message'] : json_encode($res['data'] ?? 'unknown error'));
            return [false, $err];
        }
        // Fallback: enqueue for async delivery
        $this->pdo->prepare("INSERT INTO {$this->prefix}_queue (conversation_id, wa_message_id, status, payload, created_at) VALUES (:cid, :wamid, 'pending', :payload, NOW())")
            ->execute(['cid' => $conversationId, 'wamid' => uniqid('ai_'), 'payload' => json_encode(['to' => $to, 'body' => $body])]);
        return [true, null];
    }

    private function markStatus(int $conversationId, string $waMessageId, string $status, ?string $error): void
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE {$this->waPrefix}_messages SET ai_status = :s, ai_error = :e WHERE wa_message_id = :wamid AND conversation_id = :cid");
            $stmt->execute(['s' => $status, 'e' => $error, 'wamid' => $waMessageId, 'cid' => $conversationId]);
        } catch (\Throwable $e) {
            // ignore — column may not exist yet on un-migrated hosts
        }
    }

    /**
     * Load the in-progress conversation history (previous user/assistant turns)
     * for a conversation. Returns [] if none or the session has expired.
     */
    private function loadSession(int $conversationId): array
    {
        $table = $this->prefix . '_sessions';
        try {
            $stmt = $this->pdo->prepare("SELECT history_json FROM {$table} WHERE conversation_id = :cid AND expires_at > NOW() LIMIT 1");
            $stmt->execute(['cid' => $conversationId]);
            $json = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return [];
        }
        if (!$json) return [];
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function storeSession(int $conversationId, int $companyId, string $in, string $out): void
    {
        $table = $this->prefix . '_sessions';

        // Append the latest exchange to the existing history rather than overwriting it.
        $history = $this->loadSession($conversationId);
        $history[] = ['role' => 'user', 'content' => $in];
        $history[] = ['role' => 'assistant', 'content' => $out];

        // Keep a bounded window so the prompt stays small (turn = user + assistant).
        $maxTurns = (int)($_ENV['AI_ASSISTANT_SESSION_TURNS'] ?? $_ENV['AI_WHATSAPP_SESSION_TURNS'] ?? 10);
        if ($maxTurns < 1) $maxTurns = 10;
        if (count($history) > $maxTurns * 2) {
            $history = array_slice($history, -($maxTurns * 2));
        }

        $json = json_encode($history);
        $this->pdo->prepare("INSERT INTO {$table} (conversation_id, company_id, history_json, expires_at, created_at, updated_at) VALUES (:cid, :comp, :hist, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), NOW()) ON DUPLICATE KEY UPDATE history_json = :hist2, expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR), updated_at = NOW()")
            ->execute(['cid' => $conversationId, 'comp' => $companyId, 'hist' => $json, 'hist2' => $json]);
    }

    private function saveTraining(int $companyId, string $question, string $answer): void
    {
        $table = $this->prefix . '_training';
        try {
            $this->pdo->prepare("INSERT INTO {$table} (company_id, question, answer, created_at) VALUES (:cid, :q, :a, NOW())")
                ->execute(['cid' => $companyId, 'q' => $question, 'a' => $answer]);
        } catch (\Throwable $e) {
            // ignore — table may not exist yet
        }
    }

    private function recentTraining(int $companyId, string $query, int $k = 3): array
    {
        $table = $this->prefix . '_training';
        $like = '%' . $query . '%';
        try {
            $stmt = $this->pdo->prepare("SELECT question, answer FROM {$table} WHERE company_id = :cid AND (question LIKE :q1 OR answer LIKE :q2) ORDER BY id DESC LIMIT :k");
            $stmt->bindValue(':cid', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':q1', $like);
            $stmt->bindValue(':q2', $like);
            $stmt->bindValue(':k', $k, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = "Q: " . $r['question'] . "\nA: " . $r['answer'];
        }
        return $out;
    }

    private function tryLiveQuery(string $question): ?array
    {
        if (!$this->openAI) return null;
        $schemaCtx = $this->schema->sqlSchemaContext();
        if ($schemaCtx === '') return null;

        try {
            $res = $this->openAI->chat([
                ['role' => 'system', 'content' => "You are a MySQL query generator for a workshop/garage database. Given the schema and a customer's question, output ONE read-only SELECT query to answer it.\n\nRules:\n- Output ONLY the SQL, or the exact word NO_QUERY only if the question is a greeting or clearly about nothing in the database.\n- Use only the tables/columns in SCHEMA.\n- 'how much / price / cost / charge' → SELECT name, default_amount (or the amount/price/cost column) FROM the relevant table WHERE name LIKE '%keyword%'.\n- 'how many / number of' → SELECT COUNT(*) ...\n- 'recent / latest / last' → SELECT ... ORDER BY created_at DESC LIMIT 5.\n- 'where / located / address' → SELECT address ... FROM branches.\n- Use LIKE '%word%' for name matching. No markdown, no explanations."],
                ['role' => 'user', 'content' => "SCHEMA (table: columns):\n" . $schemaCtx . "\n\nQUESTION:\n" . $question],
            ], LlmFactory::defaultModel(), 0.0);
            $sql = trim((string)($res['content'] ?? ''));
        } catch (\Throwable $e) {
            return null;
        }

        if ($sql === '' || strtoupper($sql) === 'NO_QUERY') return null;

        $err = $this->schema->validateSql($sql);
        if ($err !== null) return null;

        try {
            $rows = $this->schema->runReadOnly($sql);
            if (empty($rows)) return null;
            return ['sql' => $sql, 'rows' => $rows];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatRows(array $rows): string
    {
        if (empty($rows)) return '(no results)';
        if (count($rows) === 1 && count($rows[0]) === 1) {
            return (string)reset($rows[0]);
        }
        return json_encode($rows, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolve the tenant id for a conversation.
     *
     * Apps WITHOUT a company model set AI_ASSISTANT_TENANT_ID (e.g. 0) and
     * always pass that same value as $companyId — company_id is only a
     * scoping key in the package's own tables, never a foreign key.
     * An explicitly set id (even 0) short-circuits before any `companies`
     * table lookup, so hosts without that table pay no queries.
     */
    private function resolveCompanyId(): int
    {
        foreach (['AI_ASSISTANT_TENANT_ID', 'AI_WHATSAPP_COMPANY_ID'] as $key) {
            if (isset($_ENV[$key]) && is_numeric($_ENV[$key])) {
                return (int) $_ENV[$key];
            }
        }

        $appName = trim((string)($_ENV['APP_NAME'] ?? ''));
        if ($appName !== '') {
            try {
                $stmt = $this->pdo->prepare("SELECT id FROM companies WHERE name = :n AND is_active = 1 ORDER BY id ASC LIMIT 1");
                $stmt->execute(['n' => $appName]);
                $id = $stmt->fetchColumn();
                if ($id) return (int)$id;
            } catch (\Throwable $e) {}
        }

        try {
            $id = $this->pdo->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($id) return (int)$id;
        } catch (\Throwable $e) {}

        return 1;
    }

    private function companyContext(int $companyId): array
    {
        $ctx = [];
        if ($this->contextProvider) {
            try {
                $c = $this->contextProvider->getCompanyContext($companyId);
                if (is_array($c)) $ctx = $c;
            } catch (\Throwable $e) {}
        }
        if (empty($ctx['name'])) {
            try {
                $stmt = $this->pdo->query("SELECT name FROM companies WHERE id = " . (int)$companyId . " LIMIT 1");
                $name = $stmt->fetchColumn();
                if ($name) $ctx['name'] = $name;
            } catch (\Throwable $e) {}
        }
        return $ctx;
    }

    private function servicesText($services): string
    {
        if (empty($services)) return '';
        if (is_string($services)) return trim($services);
        if (is_array($services)) {
            $parts = [];
            foreach ($services as $s) {
                if (is_string($s) && trim($s) !== '') $parts[] = trim($s);
            }
            return implode(', ', $parts);
        }
        return '';
    }
}
