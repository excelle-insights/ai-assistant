<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\ConversationStoreInterface;
use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
use PDO;

/**
 * Chat history for every surface, stored in the host database in
 * {prefix}_conversations + {prefix}_messages.
 *
 * A thread is addressed by (tenant, channel, thread_key) rather than one
 * vendor's numeric id, so the same stored conversation works for a WhatsApp DM,
 * a web widget and the HTTP API. Message rows keep the answer's citations, data
 * and chart spec next to the text, which is what lets a widget re-render an
 * earlier turn and the assistant remember what it already said.
 *
 * Long threads fold into a rolling summary (summarize()) so memory stays inside
 * the prompt budget; the raw turns are never deleted by that.
 */
final class ConversationStore implements ConversationStoreInterface
{
    private ?PDO $pdo;
    private ?LlmClientInterface $llm;
    private int $historyTurns;
    private int $summarizeAfter;
    private int $contentLimit;

    /** @param array<string,mixed> $options */
    public function __construct(?PDO $pdo = null, ?LlmClientInterface $llm = null, array $options = [])
    {
        $this->pdo = $pdo;
        $this->llm = $llm;
        $this->historyTurns = max(0, (int) ($options['history_turns'] ?? Config::int('limits.memory_turns', 10)));
        $this->summarizeAfter = max(0, (int) ($options['summarize_after'] ?? Config::int('limits.summarize_after', 24)));
        $this->contentLimit = max(200, (int) ($options['content_limit'] ?? 8000));
    }

    public function touch(int $tenantId, string $channel, string $threadId, array $attributes = []): string
    {
        $channel = self::slug($channel, 'web');
        $threadId = trim($threadId);
        $userId = isset($attributes['user_id']) ? (string) $attributes['user_id'] : null;
        $context = (array) ($attributes['context'] ?? []);
        $title = isset($attributes['title']) ? mb_substr((string) $attributes['title'], 0, 255) : null;

        $existing = $threadId === '' ? null : $this->row(['company_id' => $tenantId, 'channel' => $channel, 'thread_key' => $threadId]);
        if ($existing !== null) {
            $values = ['last_activity_at' => Db::now()];
            if ($userId !== null && trim((string) ($existing['user_id'] ?? '')) === '') {
                $values['user_id'] = $userId;
            }
            if ($context !== []) {
                $values['context_json'] = Db::json($context + Db::decode($existing['context_json'] ?? null));
            }
            Db::updateWhere($this->connection(), $this->conversations(), $values, ['id' => (string) $existing['id']], '');
            return (string) $existing['id'];
        }

        $id = Db::uuid();
        Db::insert($this->connection(), $this->conversations(), [
            'id' => $id,
            'company_id' => $tenantId,
            'user_id' => $userId,
            'channel' => $channel,
            // NULL thread_key keeps one-shot conversations (no stable thread on the
            // surface) out of the unique index, so every call gets its own row.
            'thread_key' => $threadId === '' ? null : $threadId,
            'title' => $title,
            'context_json' => Db::json($context),
            'message_count' => 0,
            'last_activity_at' => Db::now(),
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
        return $id;
    }

    /** Resolve the thread for whoever is asking, creating it on first sight. */
    public function touchForUser(UserContext $user, array $attributes = []): string
    {
        return $this->touch($user->tenantId, $user->channel, $user->threadId, $attributes + ['user_id' => $user->userId]);
    }

    /**
     * Store one turn.
     *
     * @param array<string,mixed> $turn role, content, content_type, intent, citations,
     *                                   rows, chart, applied_filters, sql, model,
     *                                   tokens_in, tokens_out, latency_ms, status, error
     * @return int the turn number stored (seq), 0 when the thread is unknown
     */
    public function append(string $conversationId, array $turn): int
    {
        $role = self::slug((string) ($turn['role'] ?? 'user'), 'user');
        if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) {
            $role = 'user';
        }
        $conversation = $this->getConversation($conversationId);
        if ($conversation === null) {
            return 0;
        }

        $content = mb_substr(trim((string) ($turn['content'] ?? '')), 0, $this->contentLimit);
        $seq = $this->nextSeq($conversationId);
        $data = [];
        foreach (['rows', 'applied_filters', 'record_refs', 'meta'] as $key) {
            if (isset($turn[$key]) && $turn[$key] !== []) {
                $data[$key] = $key === 'rows' ? array_slice(array_values((array) $turn[$key]), 0, (int) ($turn['rows_persisted'] ?? 200)) : $turn[$key];
            }
        }

        Db::insert($this->connection(), $this->messages(), [
            'id' => Db::uuid(),
            'conversation_id' => $conversationId,
            'company_id' => (int) ($conversation['company_id'] ?? 0),
            'user_id' => isset($turn['user_id']) ? (string) $turn['user_id'] : ($conversation['user_id'] ?? null),
            'seq' => $seq,
            'role' => $role,
            'content' => $content,
            'content_type' => self::slug((string) ($turn['content_type'] ?? 'text'), 'text'),
            'intent' => isset($turn['intent']) ? self::slug((string) $turn['intent'], 'chat') : null,
            'citations_json' => Db::json(self::flatCitations((array) ($turn['citations'] ?? []))),
            'data_json' => Db::json($data),
            'chart_json' => Db::json($turn['chart'] ?? null),
            'sql_hash' => isset($turn['sql']) && $turn['sql'] !== '' ? hash('sha256', (string) $turn['sql']) : null,
            'model' => isset($turn['model']) ? mb_substr((string) $turn['model'], 0, 100) : null,
            'tokens_in' => (int) ($turn['tokens_in'] ?? 0),
            'tokens_out' => (int) ($turn['tokens_out'] ?? 0),
            'latency_ms' => isset($turn['latency_ms']) ? (int) $turn['latency_ms'] : null,
            'status' => self::slug((string) ($turn['status'] ?? 'ok'), 'ok'),
            'error' => isset($turn['error']) ? mb_substr((string) $turn['error'], 0, 2000) : null,
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        $update = ['last_activity_at' => Db::now(), 'message_count' => (int) ($conversation['message_count'] ?? 0) + 1];
        if ($role === 'user' && trim((string) ($conversation['title'] ?? '')) === '' && $content !== '') {
            $update['title'] = mb_substr($content, 0, 80);
        }
        Db::updateWhere($this->connection(), $this->conversations(), $update, ['id' => $conversationId], '');
        $this->summarizeIfNeeded($conversationId, (int) $update['message_count']);

        return $seq;
    }

    /**
     * Recent turns, oldest first — the shape a chat client sends to a model.
     *
     * @return array<int,array{role:string,content:string,created_at:string,intent:string}>
     */
    public function history(string $conversationId, int $limit = 10): array
    {
        $limit = $limit > 0 ? $limit : $this->historyTurns;
        if ($limit <= 0 || $conversationId === '') {
            return [];
        }
        $rows = Db::select(
            $this->connection(),
            $this->messages(),
            ['conversation_id' => $conversationId],
            [],
            [['seq', 'desc'], ['created_at', 'desc']],
            $limit,
            '',
            ['role', 'content', 'created_at', 'intent', 'seq']
        );
        $turns = [];
        foreach (array_reverse($rows) as $row) {
            $role = (string) ($row['role'] ?? '');
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $turns[] = [
                'role' => $role,
                'content' => $content,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'intent' => (string) ($row['intent'] ?? ''),
            ];
        }
        return $turns;
    }

    /** Everything about a thread, oldest first — what a widget re-renders. */
    public function transcript(string $conversationId, int $limit = 200): array
    {
        $conversation = $this->getConversation($conversationId);
        if ($conversation === null) {
            return ['conversation' => null, 'messages' => []];
        }
        $rows = Db::select($this->connection(), $this->messages(), ['conversation_id' => $conversationId], [], [['seq', 'asc'], ['created_at', 'asc']], $limit);
        $messages = [];
        foreach ($rows as $row) {
            $data = Db::decode($row['data_json'] ?? null);
            $messages[] = [
                'id' => (string) ($row['id'] ?? ''),
                'seq' => (int) ($row['seq'] ?? 0),
                'role' => (string) ($row['role'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'content_type' => (string) ($row['content_type'] ?? 'text'),
                'intent' => (string) ($row['intent'] ?? ''),
                'citations' => Db::decode($row['citations_json'] ?? null),
                'chart' => Db::decode($row['chart_json'] ?? null),
                'rows' => (array) ($data['rows'] ?? []),
                'applied_filters' => (array) ($data['applied_filters'] ?? []),
                'meta' => (array) ($data['meta'] ?? []),
                'model' => (string) ($row['model'] ?? ''),
                'status' => (string) ($row['status'] ?? 'ok'),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return ['conversation' => self::publicConversation($conversation), 'messages' => $messages];
    }

    /**
     * Prompt memory: the rolling summary plus the recent turns it does not
     * cover yet, and any page/record context saved with the thread.
     *
     * @return array{summary:string,turns:array<int,array{role:string,content:string}>,context:array<string,mixed>}
     */
    public function memory(string $conversationId): array
    {
        $conversation = $this->getConversation($conversationId);
        if ($conversation === null) {
            return ['summary' => '', 'turns' => [], 'context' => []];
        }
        return [
            'summary' => trim((string) ($conversation['summary'] ?? '')),
            'turns' => array_map(
                static fn (array $t): array => ['role' => $t['role'], 'content' => $t['content']],
                $this->history($conversationId)
            ),
            'context' => Db::decode($conversation['context_json'] ?? null),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $tenantId, string $userId, int $limit = 50): array
    {
        $where = ['company_id' => $tenantId];
        if ($userId !== '') {
            $where['user_id'] = $userId;
        }
        $rows = Db::select(
            $this->connection(),
            $this->conversations(),
            $where,
            [],
            [['pinned', 'desc'], ['last_activity_at', 'desc']],
            $limit
        );
        return array_map(self::publicConversation(...), $rows);
    }

    public function rename(string $conversationId, string $title): bool
    {
        $clean = mb_substr(trim($title), 0, 255);
        if ($clean === '') {
            return false;
        }
        return Db::updateWhere($this->connection(), $this->conversations(), ['title' => $clean], ['id' => $conversationId], '') > 0;
    }

    public function setPinned(string $conversationId, bool $pinned): bool
    {
        return Db::updateWhere($this->connection(), $this->conversations(), ['pinned' => $pinned ? 1 : 0], ['id' => $conversationId], '') > 0;
    }

    public function setSummary(string $conversationId, string $summary): void
    {
        Db::updateWhere($this->connection(), $this->conversations(), ['summary' => mb_substr($summary, 0, 4000)], ['id' => $conversationId], '');
    }

    /** Opaque read-only link, so a host can hand a thread to someone else. */
    public function share(string $conversationId): string
    {
        $token = bin2hex(random_bytes(16));
        Db::updateWhere($this->connection(), $this->conversations(), ['shared_token' => $token], ['id' => $conversationId], '');
        return $token;
    }

    public function revokeShare(string $conversationId): bool
    {
        return Db::updateWhere($this->connection(), $this->conversations(), ['shared_token' => null], ['id' => $conversationId], '') > 0;
    }

    public function findByShare(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = $this->row(['shared_token' => $token], ['id']);
        return $row === null ? null : $this->transcript((string) $row['id']);
    }

    /** Delete a thread and its messages, scoped to the owning tenant. */
    public function delete(string $conversationId): bool
    {
        $conversation = $this->getConversation($conversationId);
        if ($conversation === null) {
            return false;
        }
        $conn = $this->connection();
        $conn->prepare('DELETE FROM `' . $this->messages() . '` WHERE conversation_id = :id AND company_id = :tenant')
            ->execute([':id' => $conversationId, ':tenant' => (int) ($conversation['company_id'] ?? 0)]);
        $conn->prepare('DELETE FROM `' . $this->conversations() . '` WHERE id = :id')->execute([':id' => $conversationId]);
        return true;
    }

    public function getConversation(string $conversationId): ?array
    {
        return $conversationId === '' ? null : $this->row(['id' => $conversationId]);
    }

    /** @return array<int,array<string,mixed>> messages in one tenant matching a term */
    public function search(int $tenantId, string $term, int $limit = 30): array
    {
        if (trim($term) === '') {
            return [];
        }
        return Db::select($this->connection(), $this->messages(), ['company_id' => $tenantId], ['content' => $term], [['created_at', 'desc']], $limit);
    }

    /**
     * Fold the older part of a long thread into the summary. Without an LLM the
     * summary is left as-is and history simply stays truncated.
     */
    public function summarize(string $conversationId): string
    {
        $conversation = $this->getConversation($conversationId);
        $previous = trim((string) ($conversation['summary'] ?? ''));
        if ($this->llm === null || $conversation === null) {
            return $previous;
        }
        $rows = Db::select($this->connection(), $this->messages(), ['conversation_id' => $conversationId], [], [['seq', 'asc']], 200, '', ['role', 'content', 'seq']);
        if (count($rows) <= $this->historyTurns) {
            return $previous;
        }
        $lines = [];
        foreach (array_slice($rows, 0, count($rows) - $this->historyTurns) as $row) {
            $role = (string) ($row['role'] ?? '');
            $content = mb_substr(trim((string) ($row['content'] ?? '')), 0, 400);
            if ($content !== '' && in_array($role, ['user', 'assistant'], true)) {
                $lines[] = $role . ': ' . $content;
            }
        }
        if ($lines === []) {
            return $previous;
        }
        try {
            $reply = $this->llm->chat([
                ['role' => 'system', 'content' => 'Summarise the conversation so far in at most 6 short bullets. Keep names, numbers, dates and any decision that was made. Reply with the bullets only.'],
                ['role' => 'user', 'content' => ($previous !== '' ? "Earlier summary:\n" . $previous . "\n\n" : '') . implode("\n", $lines)],
            ], '', 0.2);
            $summary = trim((string) ($reply['content'] ?? ''));
        } catch (\Throwable) {
            return $previous;
        }
        if ($summary === '') {
            return $previous;
        }
        $this->setSummary($conversationId, $summary);
        return $summary;
    }

    /** Turn number inside the thread; exact ordering cannot rely on second-resolution timestamps. */
    private function nextSeq(string $conversationId): int
    {
        $stmt = $this->connection()->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM `' . $this->messages() . '` WHERE conversation_id = :id');
        $stmt->execute([':id' => $conversationId]);
        return (int) $stmt->fetchColumn();
    }

    private function summarizeIfNeeded(string $conversationId, int $messageCount): void
    {
        if ($this->summarizeAfter <= 0 || $this->llm === null || $messageCount < $this->summarizeAfter) {
            return;
        }
        try {
            $this->summarize($conversationId);
        } catch (\Throwable) {
            // Memory folding must never break the answer the user is waiting for.
        }
    }

    /** @param array<int,mixed> $citations @return array<int,array<string,mixed>> */
    private static function flatCitations(array $citations): array
    {
        $out = [];
        foreach ($citations as $citation) {
            if (is_object($citation) && method_exists($citation, 'toArray')) {
                $out[] = $citation->toArray();
            } elseif (is_array($citation)) {
                $out[] = $citation;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function publicConversation(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'channel' => (string) ($row['channel'] ?? 'web'),
            'thread_key' => (string) ($row['thread_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'pinned' => (int) ($row['pinned'] ?? 0) === 1,
            'message_count' => (int) ($row['message_count'] ?? 0),
            'has_summary' => trim((string) ($row['summary'] ?? '')) !== '',
            'shared' => trim((string) ($row['shared_token'] ?? '')) !== '',
            'last_activity_at' => (string) ($row['last_activity_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $where
     * @param string[] $columns
     */
    private function row(array $where, array $columns = []): ?array
    {
        $rows = Db::select($this->connection(), $this->conversations(), $where, [], [], 1, '', $columns === [] ? null : $columns);
        return $rows[0] ?? null;
    }

    private function conversations(): string
    {
        return Db::requireTable($this->connection(), 'conversations');
    }

    private function messages(): string
    {
        return Db::requireTable($this->connection(), 'messages');
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException(
                'No database connection available to the AI assistant. Pass the host PDO to the service, '
                . 'or configure the DSN/DB_* variables in .env.'
            );
        }
        return $this->pdo;
    }

    private static function slug(string $value, string $fallback): string
    {
        $clean = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(trim($value)));
        return $clean === '' ? $fallback : $clean;
    }
}
