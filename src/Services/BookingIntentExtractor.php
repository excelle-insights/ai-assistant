<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Contracts\LlmClientInterface;
use ExcelleInsights\AiAssistant\Support\BookingIntent;

/**
 * BookingIntentExtractor — detects booking requests in a customer message
 * (plus the assistant's reply for context) and extracts the service wanted
 * and the preferred date.
 *
 * Design:
 *  - A cheap multilingual keyword gate runs first so the LLM is only called
 *    when the message plausibly asks to book. No LLM configured (or LLM
 *    failure) still yields a keyword-level intent, just without details.
 *  - Preferred dates are normalised to Y-m-d against the reference "today"
 *    supplied by the caller (host timezone), so "Friday", "tomorrow",
 *    "next week" resolve correctly regardless of app.
 *  - Returns null when there is no booking intent. Never touches any
 *    application table — hosts persist via BookingHandlerInterface.
 */
class BookingIntentExtractor
{
    /**
     * Booking-ish words across languages commonly seen in these chats.
     * Kept intentionally broad; the LLM (or the host) makes the final call.
     */
    private const HINT_PATTERN = '/\b(book|booking|appointment|appointments|schedule|reschedule|reserve|reservation|slot|visit|come in|bring(?:ing)?(?: it| the| my)?|service date|bridg|taka kuja|nataka|napenda|miadi|rendez-vous|termin|cita| turno|agendar|marcar)\b/i';

    public function __construct(
        private ?LlmClientInterface $llm = null,
        private string $timezone = 'UTC',
        private string $model = '',
    ) {
    }

    public function looksLikeBooking(string $message, string $reply = ''): bool
    {
        return (bool) preg_match(self::HINT_PATTERN, $message . ' ' . $reply);
    }

    /**
     * @return BookingIntent|null null when no booking intent detected.
     */
    public function extract(
        int $companyId,
        int $conversationId,
        string $contactPhone,
        ?string $contactName,
        string $message,
        string $reply = '',
        array $extra = [],
    ): ?BookingIntent {
        if (!$this->looksLikeBooking($message, $reply)) {
            return null;
        }

        $service = null;
        $date = null;
        $confidence = 0.5; // keyword-level

        $llmResult = $this->extractWithLlm($message, $reply);
        if ($llmResult !== null) {
            if (!($llmResult['wants_booking'] ?? false)) {
                return null; // LLM overruled the keyword gate
            }
            $service = $llmResult['service'] ?? null;
            $date = $this->normaliseDate($llmResult['preferred_date'] ?? null);
            $confidence = (float) ($llmResult['confidence'] ?? 0.8);
        }

        return new BookingIntent(
            companyId: $companyId,
            conversationId: $conversationId,
            contactPhone: $contactPhone,
            contactName: $contactName,
            messageBody: $message,
            aiReply: $reply,
            service: $service,
            preferredDate: $date,
            confidence: max(0.0, min(1.0, $confidence)),
            extra: $extra,
        );
    }

    /**
     * LLM pass: returns ['wants_booking'=>bool,'service'=>?string,
     * 'preferred_date'=>?string,'confidence'=>float] or null on any failure.
     */
    private function extractWithLlm(string $message, string $reply): ?array
    {
        if (!$this->llm) {
            return null;
        }
        try {
            $tz = new \DateTimeZone($this->timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d (l)');

        $system = 'You extract booking requests from customer-support chats. '
            . "Today is {$today}. "
            . 'Reply with ONLY a JSON object, no markdown, no explanation, with keys: '
            . '{"wants_booking": boolean, "service": string|null, "preferred_date": string|null, "confidence": number}. '
            . 'wants_booking is true when the customer asks to book, schedule, reserve or visit for a service. '
            . 'service is the short service name they want (or null). '
            . 'preferred_date is the date they want as YYYY-MM-DD, resolving relative phrases ("tomorrow", "Friday", "next week") against today (or null when none is mentioned). '
            . 'confidence is 0-1.';

        try {
            $res = $this->llm->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "CUSTOMER:\n{$message}\n\nASSISTANT REPLY:\n{$reply}"],
            ], $this->model, 0.0);
        } catch (\Throwable $e) {
            return null;
        }

        $json = trim((string) ($res['content'] ?? ''));
        if ($json === '') {
            return null;
        }
        // Tolerate code fences.
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $json);
        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('wants_booking', $data)) {
            return null;
        }
        return [
            'wants_booking'  => (bool) $data['wants_booking'],
            'service'        => isset($data['service']) && is_string($data['service']) && trim($data['service']) !== '' ? trim($data['service']) : null,
            'preferred_date' => isset($data['preferred_date']) && is_string($data['preferred_date']) ? trim($data['preferred_date']) : null,
            'confidence'     => isset($data['confidence']) && is_numeric($data['confidence']) ? (float) $data['confidence'] : 0.8,
        ];
    }

    private function normaliseDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $raw;
        }
        try {
            $tz = new \DateTimeZone($this->timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        try {
            return (new \DateTimeImmutable($raw, $tz))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
