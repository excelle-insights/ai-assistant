<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use Throwable;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Support\Db;

/**
 * Thumbs/ratings on assistant answers plus an admin review queue, stored in
 * {prefix}_feedback. New rows land in 'pending'; markReviewed() moves them to
 * accepted|rejected|converted. convertToKnowledge() promotes an approved Q&A
 * into {prefix}_knowledge — deliberately self-contained (a direct insert with a
 * content hash, no dependency on KnowledgeService).
 *
 * Storage access goes through small protected seams so the pure parts
 * (rating/submit normalisation, content hashing, aggregation maths) are testable
 * without PDO.
 */
class FeedbackService
{
    private const VALID_ACTIONS = ['accepted', 'rejected', 'converted'];

    /** @var callable(Throwable):void|null */
    private $onError;

    /**
     * @param callable(Throwable):void|null $onError
     */
    public function __construct(private ?PDO $pdo = null, ?callable $onError = null)
    {
        $this->onError = $onError;
    }

    /**
     * Record a rating.
     *
     * @param string|int|array<string,mixed> $target message id, or an array with
     *        keys message_id / conversation_id / question / answer
     * @param string $rating 'up'|'down' (also thumbs_up/thumbs_down/1-5) | 1-5 scale
     */
    public function submit(
        string|int|array $target,
        string $rating,
        ?string $reason = null,
        ?string $comment = null,
        ?UserContext $ctx = null,
    ): int|string {
        $payload = $this->normaliseSubmit($target, $rating, $reason, $comment, $ctx);
        $payload = $this->enrichFromMessages($payload);
        try {
            return $this->insertFeedback($payload);
        } catch (Throwable $e) {
            $this->report($e);
            return 0;
        }
    }

    /**
     * Admin review queue for one tenant, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForAdmin(int $companyId, string $status = 'pending', int $limit = 50): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $table = Db::requireTable($this->pdo, 'feedback');
            $sql = 'SELECT * FROM `' . $table . '` WHERE `company_id` = :c';
            $bind = ['c' => $companyId];
            if ($status !== '' && $status !== 'all') {
                $sql .= ' AND `status` = :s';
                $bind['s'] = $status;
            }
            $sql .= ' ORDER BY `created_at` DESC LIMIT ' . max(1, $limit);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->report($e);
            return [];
        }
    }

    /**
     * Move a feedback row out of the queue. `$action` is accepted|rejected|
     * converted (defaults to 'reviewed' when omitted).
     */
    public function markReviewed(int $id, ?string $action = null, ?string $reviewedBy = null): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $status = $action === null ? 'reviewed' : (in_array($action, self::VALID_ACTIONS, true) ? $action : 'reviewed');
        try {
            $table = Db::requireTable($this->pdo, 'feedback');
            $stmt = $this->pdo->prepare(
                'UPDATE `' . $table . '` SET `status` = :s, `reviewed_by` = :r, `updated_at` = :u'
                . ' WHERE `id` = :id'
            );
            $stmt->execute(['s' => $status, 'r' => $reviewedBy, 'u' => Db::now(), 'id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->report($e);
            return false;
        }
    }

    /**
     * Adoption + quality rollup for a tenant over a date range.
     *
     * @return array<string,mixed>
     */
    public function aggregate(int $companyId, string $from, string $to): array
    {
        if ($this->pdo === null) {
            return ['total' => 0, 'helpful' => 0, 'negative' => 0, 'helpful_pct' => 0.0, 'by_reason' => [], 'unanswered' => 0];
        }
        try {
            $table = Db::requireTable($this->pdo, 'feedback');
            $stmt = $this->pdo->prepare(
                'SELECT `rating`, `reason`, COUNT(*) AS n FROM `' . $table . '`'
                . ' WHERE `company_id` = :c AND `created_at` BETWEEN :f AND :t'
                . ' GROUP BY `rating`, `reason`'
            );
            $stmt->execute(['c' => $companyId, 'f' => $this->start($from), 't' => $this->end($to)]);

            $helpful = $negative = 0;
            $byReason = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $n = (int) $row['n'];
                if ($this->isHelpful((string) $row['rating'])) {
                    $helpful += $n;
                } else {
                    $negative += $n;
                    $reason = (string) ($row['reason'] ?? 'unspecified');
                    $byReason[$reason] = ($byReason[$reason] ?? 0) + $n;
                }
            }
            $total = $helpful + $negative;

            $unanswered = 0;
            if (Db::tableExists($this->pdo, 'usage_daily')) {
                $uStmt = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(`unanswered`),0) FROM `' . Db::table('usage_daily') . '`'
                    . ' WHERE `company_id` = :c AND `stat_date` BETWEEN :f AND :t'
                );
                $uStmt->execute(['c' => $companyId, 'f' => $this->day($from), 't' => $this->day($to)]);
                $unanswered = (int) $uStmt->fetchColumn();
            }

            return [
                'total' => $total,
                'helpful' => $helpful,
                'negative' => $negative,
                'helpful_pct' => $total > 0 ? round($helpful / $total * 100, 1) : 0.0,
                'by_reason' => $byReason,
                'unanswered' => $unanswered,
            ];
        } catch (Throwable $e) {
            $this->report($e);
            return ['total' => 0, 'helpful' => 0, 'negative' => 0, 'helpful_pct' => 0.0, 'by_reason' => [], 'unanswered' => 0];
        }
    }

    /**
     * Promote a reviewed Q&A into {prefix}_knowledge with a content hash so
     * re-running is idempotent. Self-contained — no KnowledgeService dependency.
     *
     * @return array{ok:bool, knowledge_id?:int, skipped?:bool, reason?:string}
     */
    public function convertToKnowledge(int $feedbackId): array
    {
        if ($this->pdo === null) {
            return ['ok' => false, 'reason' => 'no_database'];
        }
        try {
            $table = Db::requireTable($this->pdo, 'feedback');
            $stmt = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE `id` = :id LIMIT 1');
            $stmt->execute(['id' => $feedbackId]);
            $fb = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fb) {
                return ['ok' => false, 'reason' => 'feedback_not_found'];
            }
            $question = trim((string) ($fb['question'] ?? ''));
            $answer = trim((string) ($fb['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                return ['ok' => false, 'reason' => 'incomplete_qa'];
            }
            if (!Db::tableExists($this->pdo, 'knowledge')) {
                return ['ok' => false, 'reason' => 'knowledge_table_missing'];
            }

            $companyId = (int) ($fb['company_id'] ?? 0);
            $content = $question . "\n\n" . $answer;
            $hash = $this->contentHash($question, $answer);

            $know = Db::table('knowledge');
            $dupe = $this->pdo->prepare(
                'SELECT `id` FROM `' . $know . '` WHERE `company_id` = :c AND `content_hash` = :h LIMIT 1'
            );
            $dupe->execute(['c' => $companyId, 'h' => $hash]);
            if ($existingId = $dupe->fetchColumn()) {
                $this->markReviewed($feedbackId, 'converted', (string) ($fb['user_id'] ?? 'system'));
                return ['ok' => true, 'skipped' => true, 'knowledge_id' => (int) $existingId];
            }

            $id = Db::insert($this->pdo, $know, [
                'company_id' => $companyId,
                'title' => mb_substr($question, 0, 250),
                'content' => $content,
                'content_hash' => $hash,
                'source' => 'feedback',
                'status' => 'active',
                'scope' => 'global',
                'metadata_json' => Db::json(['feedback_id' => $feedbackId, 'message_id' => $fb['message_id'] ?? null]),
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
            ]);
            $this->markReviewed($feedbackId, 'converted', (string) ($fb['user_id'] ?? 'system'));
            return ['ok' => true, 'knowledge_id' => $id];
        } catch (Throwable $e) {
            $this->report($e);
            return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ── pure logic (no PDO) ────────────────────────────────────────────────

    /**
     * Fold submit() inputs into a canonical row. Pure & testable.
     *
     * @return array<string,mixed>
     */
    public function normaliseSubmit(
        string|int|array $target,
        string $rating,
        ?string $reason,
        ?string $comment,
        ?UserContext $ctx,
    ): array {
        $target = is_array($target) ? $target : ['message_id' => (string) $target];
        return [
            'company_id' => (int) ($target['company_id'] ?? $ctx?->tenantId ?? 0),
            'message_id' => $this->str($target['message_id'] ?? null),
            'conversation_id' => $this->str($target['conversation_id'] ?? $ctx?->threadId ?? null),
            'user_id' => $this->str($target['user_id'] ?? $ctx?->userId ?? null),
            'rating' => $this->normaliseRating($rating),
            'reason' => $this->str($reason),
            'comment' => $this->str($comment),
            'question' => $this->str($target['question'] ?? null),
            'answer' => $this->str($target['answer'] ?? null),
            'status' => 'pending',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ];
    }

    /** Map thumbs/scale variants onto a stored rating token. Pure. */
    public function normaliseRating(string $rating): string
    {
        $r = strtolower(trim($rating));
        if (in_array($r, ['up', 'thumbs_up', 'thumb_up', 'positive', 'helpful', 'good', '1', '+'], true)) {
            return 'up';
        }
        if (in_array($r, ['down', 'thumbs_down', 'thumb_down', 'negative', 'unhelpful', 'bad', '0', '-1', '-'], true)) {
            return 'down';
        }
        if (ctype_digit($r)) {
            $n = (int) $r;
            if ($n >= 1 && $n <= 5) {
                return (string) $n;
            }
        }
        return $r !== '' ? substr($r, 0, 10) : 'down';
    }

    public function isHelpful(string $rating): bool
    {
        return in_array($rating, ['up', '4', '5'], true);
    }

    /** Stable content hash for a promoted Q&A. Pure. */
    public function contentHash(string $question, string $answer): string
    {
        $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $question) ?? $question) . ' || ' . trim(preg_replace('/\s+/', ' ', $answer) ?? $answer));
        return hash('sha256', $normalised);
    }

    // ── storage seams ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $payload */
    protected function insertFeedback(array $payload): int|string
    {
        if ($this->pdo === null) {
            return 0;
        }
        $table = Db::requireTable($this->pdo, 'feedback');
        $values = array_filter(
            $payload,
            static fn ($v): bool => $v !== null,
        );
        return Db::insert($this->pdo, $table, $values);
    }

    /**
     * Best-effort: pull the answer + preceding question from {prefix}_messages
     * when only a message id was supplied. Never throws.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function enrichFromMessages(array $payload): array
    {
        if ($this->pdo === null || ($payload['answer'] !== null && $payload['question'] !== null) || $payload['message_id'] === null) {
            return $payload;
        }
        try {
            if (!Db::tableExists($this->pdo, 'messages')) {
                return $payload;
            }
            $table = Db::table('messages');
            $stmt = $this->pdo->prepare('SELECT `conversation_id`, `content`, `created_at`, `company_id` FROM `' . $table . '` WHERE `id` = :id LIMIT 1');
            $stmt->execute(['id' => $payload['message_id']]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$msg) {
                return $payload;
            }
            $payload['answer'] ??= $msg['content'] ?? null;
            $payload['conversation_id'] ??= $msg['conversation_id'] ?? null;
            $payload['company_id'] = $payload['company_id'] ?: (int) ($msg['company_id'] ?? 0);

            if ($payload['question'] === null && !empty($msg['conversation_id'])) {
                $q = $this->pdo->prepare(
                    'SELECT `content` FROM `' . $table . '` WHERE `conversation_id` = :c AND `role` = :r'
                    . ' AND `created_at` <= :t ORDER BY `created_at` DESC LIMIT 1'
                );
                $q->execute(['c' => $msg['conversation_id'], 'r' => 'user', 't' => $msg['created_at']]);
                $payload['question'] = $q->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {
            $this->report($e);
        }
        return $payload;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function day(string $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : date('Y-m-d');
    }

    private function start(string $v): string
    {
        return $this->day($v) . ' 00:00:00';
    }

    private function end(string $v): string
    {
        return $this->day($v) . ' 23:59:59';
    }

    private function report(Throwable $e): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($e);
            } catch (Throwable) {
            }
        }
    }
}
