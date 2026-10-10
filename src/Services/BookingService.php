<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use PDO;
use ExcelleInsights\AiAssistant\Support\BookingIntent;
use ExcelleInsights\AiAssistant\Support\TablePrefix;

class BookingService
{
    private string $bookingsTable;
    private string $messagesTable;

    public function __construct(private PDO $pdo)
    {
        $prefix = TablePrefix::get();
        $this->bookingsTable = $prefix . '_bookings';
        $this->messagesTable = $prefix . '_booking_messages';
    }

    /**
     * Persist a booking detected in an AI conversation. Reuses a recent open
     * booking for the same contact and tops up missing details; otherwise
     * creates a new booking and assigns a booking number.
     *
     * @return array<string,mixed>|null
     */
    public function recordFromIntent(BookingIntent $intent, array $conversation = []): ?array
    {
        $phone  = trim((string) $intent->contactPhone);
        $userId = trim((string) ($conversation['contact_user_id'] ?? ($intent->extra['contact_user_id'] ?? '')));
        if ($phone === '' && $userId === '') {
            return null;
        }

        $existing = $this->findRecentForContact($intent->companyId, $phone !== '' ? $phone : null, $userId !== '' ? $userId : null);
        if ($existing) {
            $this->topUp((int) $existing['id'], $intent->contactName, $intent->service, $intent->preferredDate);
            $this->addMessage((int) $existing['id'], 'customer', $intent->contactName, $intent->messageBody, false);
            if ($intent->aiReply !== '') {
                $this->addMessage((int) $existing['id'], 'ai', 'AI Assistant', $intent->aiReply, true);
            }
            return $this->get((int) $existing['id']);
        }

        $metadata = json_encode([
            'channel'    => $intent->extra['channel'] ?? 'whatsapp',
            'confidence' => $intent->confidence,
            'extra'      => $intent->extra,
        ], JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare("
            INSERT INTO {$this->bookingsTable}
                (company_id, conversation_id, channel, contact_user_id, contact_phone, contact_name,
                 service, preferred_date, status, source_message, ai_reply, confidence, metadata)
            VALUES
                (:company_id, :conversation_id, :channel, :contact_user_id, :contact_phone, :contact_name,
                 :service, :preferred_date, 'pending', :source_message, :ai_reply, :confidence, :metadata)
        ")->execute([
            'company_id'       => $intent->companyId,
            'conversation_id'  => $intent->conversationId ?: null,
            'channel'          => (string) ($intent->extra['channel'] ?? 'whatsapp'),
            'contact_user_id'  => $userId !== '' ? $userId : null,
            'contact_phone'    => $phone !== '' ? $phone : null,
            'contact_name'     => $intent->contactName !== null && $intent->contactName !== '' ? $intent->contactName : null,
            'service'          => $intent->service ?: mb_substr($intent->messageBody, 0, 500),
            'preferred_date'   => $intent->preferredDate,
            'source_message'   => $intent->messageBody,
            'ai_reply'         => $intent->aiReply,
            'confidence'       => $intent->confidence,
            'metadata'         => $metadata,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            return null;
        }

        $number = $this->makeBookingNumber($id);
        $this->pdo->prepare("UPDATE {$this->bookingsTable} SET booking_number = :n WHERE id = :id")
            ->execute(['n' => $number, 'id' => $id]);

        $this->addMessage($id, 'customer', $intent->contactName, $intent->messageBody, false);
        if ($intent->aiReply !== '') {
            $this->addMessage($id, 'ai', 'AI Assistant', $intent->aiReply, true);
        }

        return $this->get($id);
    }

    public function findRecentForContact(int $companyId, ?string $phone, ?string $userId, int $days = 7): ?array
    {
        [$where, $params] = $this->contactWhere($companyId, $phone, $userId);
        if ($where === '') {
            return null;
        }
        $params[':days'] = $days;
        $sql = "SELECT * FROM {$this->bookingsTable}
                WHERE {$where}
                  AND status IN ('pending','confirmed','rescheduled')
                  AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listForContact(int $companyId, ?string $phone, ?string $userId, int $limit = 5): array
    {
        [$where, $params] = $this->contactWhere($companyId, $phone, $userId);
        if ($where === '') {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->bookingsTable} WHERE {$where} ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->bookingsTable} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByNumber(string $number): ?array
    {
        if ($number === '') return null;
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->bookingsTable} WHERE booking_number = :n LIMIT 1");
        $stmt->execute(['n' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listForCompany(int $companyId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->companyWhere($companyId, $filters);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->bookingsTable} WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countForCompany(int $companyId, array $filters = []): int
    {
        [$where, $params] = $this->companyWhere($companyId, $filters);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->bookingsTable} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function setStatus(int $id, string $status): bool
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['pending', 'confirmed', 'rescheduled', 'cancelled', 'completed'], true)) {
            return false;
        }
        $extra = '';
        $params = ['status' => $status, 'id' => $id];
        if ($status === 'confirmed') {
            $extra = ', confirmed_at = COALESCE(confirmed_at, NOW())';
        } elseif ($status === 'cancelled') {
            $extra = ', cancelled_at = NOW()';
        }
        $stmt = $this->pdo->prepare("UPDATE {$this->bookingsTable} SET status = :status{$extra}, updated_at = NOW() WHERE id = :id");
        return $stmt->execute($params);
    }

    public function setPreferredDate(int $id, ?string $date): bool
    {
        $stmt = $this->pdo->prepare("UPDATE {$this->bookingsTable} SET preferred_date = :d, status = CASE WHEN status = 'cancelled' THEN status ELSE 'rescheduled' END, updated_at = NOW() WHERE id = :id");
        return $stmt->execute(['d' => $date, 'id' => $id]);
    }

    public function update(int $id, array $fields): bool
    {
        $allowed = ['service', 'preferred_date', 'contact_name', 'contact_phone', 'contact_user_id', 'status', 'metadata'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = "{$column} = :{$column}";
                $params[$column] = is_array($fields[$column]) ? json_encode($fields[$column], JSON_UNESCAPED_UNICODE) : $fields[$column];
            }
        }
        if (!$sets) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';
        $stmt = $this->pdo->prepare("UPDATE {$this->bookingsTable} SET " . implode(', ', $sets) . " WHERE id = :id");
        return $stmt->execute($params);
    }

    public function addMessage(int $bookingId, string $senderType, ?string $senderName, string $message, bool $isAi = false, ?int $senderId = null): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->messagesTable}
                (booking_id, sender_type, sender_name, sender_id, message, is_ai, created_at)
            VALUES (:bid, :type, :name, :sid, :msg, :ai, NOW())
        ");
        $stmt->execute([
            'bid'  => $bookingId,
            'type' => $senderType,
            'name' => $senderName !== null && $senderName !== '' ? mb_substr($senderName, 0, 150) : null,
            'sid'  => $senderId,
            'msg'  => $message,
            'ai'   => $isAi ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function messages(int $bookingId, ?int $sinceId = null): array
    {
        if ($sinceId !== null) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->messagesTable} WHERE booking_id = :bid AND id > :since ORDER BY created_at ASC, id ASC");
            $stmt->execute(['bid' => $bookingId, 'since' => $sinceId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->messagesTable} WHERE booking_id = :bid ORDER BY created_at ASC, id ASC");
            $stmt->execute(['bid' => $bookingId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function topUp(int $id, ?string $name, ?string $service, ?string $preferredDate): void
    {
        $sets = [];
        $params = ['id' => $id];
        if ($name !== null && $name !== '') {
            $sets[] = "contact_name = CASE WHEN contact_name IS NULL OR contact_name = '' THEN :name ELSE contact_name END";
            $params['name'] = mb_substr($name, 0, 150);
        }
        if ($service !== null && $service !== '') {
            $sets[] = "service = CASE WHEN service IS NULL OR service = '' OR service LIKE 'book%' THEN :svc ELSE service END";
            $params['svc'] = mb_substr($service, 0, 500);
        }
        if ($preferredDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) {
            $sets[] = "preferred_date = COALESCE(preferred_date, :pdate)";
            $params['pdate'] = $preferredDate;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $this->pdo->prepare("UPDATE {$this->bookingsTable} SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    }

    private function makeBookingNumber(int $id): string
    {
        return 'BK-' . date('Ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    private function contactWhere(int $companyId, ?string $phone, ?string $userId): array
    {
        $clauses = [];
        $params = [':company_id' => $companyId];
        if ($userId !== null && $userId !== '') {
            $clauses[] = 'contact_user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        if ($phone !== null && $phone !== '') {
            $clauses[] = 'contact_phone = :phone';
            $params[':phone'] = $phone;
        }
        if (!$clauses) {
            return ['', []];
        }
        return ['company_id = :company_id AND (' . implode(' OR ', $clauses) . ')', $params];
    }

    private function companyWhere(int $companyId, array $filters): array
    {
        $where = 'company_id = :company_id';
        $params = [':company_id' => $companyId];
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['phone'])) {
            $where .= ' AND contact_phone = :phone';
            $params[':phone'] = $filters['phone'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (booking_number LIKE :search OR contact_name LIKE :search2 OR service LIKE :search3)';
            $like = '%' . $filters['search'] . '%';
            $params[':search'] = $like;
            $params[':search2'] = $like;
            $params[':search3'] = $like;
        }
        return [$where, $params];
    }
}
