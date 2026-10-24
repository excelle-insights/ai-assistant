<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Channels;

use ExcelleInsights\AiAssistant\Contracts\ChannelInterface;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\OutboundMessage;
use ExcelleInsights\AiAssistant\Support\Config;

/**
 * WhatsApp as a delivery surface — the text, the 4096-character limit, and the
 * webhook retries.
 *
 * The package never calls a WhatsApp SDK. The host hands over one callable (Meta
 * Cloud API, Twilio, MessageBird, 360dialog, its own class) and this adapter
 * handles what is specific to WhatsApp as a *chat*: markdown it cannot render,
 * messages that must be split, and sources that have to survive as plain text.
 *
 *     $wa = new WhatsAppChannel(
 *         sender: fn (string $to, string $body, array $meta) => MyApi::send($to, $body),
 *     );
 *     $wa->send($answer, WhatsAppChannel::outboundFor($inbound));
 *
 * The sender may return a bool, or an array with `ok`/`success` plus an optional
 * `error` and a message id under any of `external_id|message_id|wamid|id`, or the
 * raw Meta response (`messages[0].id`, `data.error.message`).
 */
final class WhatsAppChannel implements ChannelInterface
{
    /** WhatsApp's own limit for a text message, in characters. */
    public const MAX_CHARS = 4096;

    /** @var array<string,mixed> */
    private array $options;

    /** @var array<string,array{ok: bool, error?: string, external_id?: string}> */
    private array $delivered = [];

    /**
     * @param callable(string,string,array<string,mixed>):(bool|array<string,mixed>) $sender
     * @param array<string,mixed> $options max_message_chars, show_sources, max_rows
     */
    public function __construct(private $sender, array $options = [])
    {
        if (!is_callable($this->sender)) {
            throw new \InvalidArgumentException('WhatsAppChannel needs a sender callable(string $to, string $body, array $meta).');
        }
        $this->options = $options;
    }

    public function id(): string
    {
        return 'whatsapp';
    }

    /**
     * Deliver the answer, splitting it into as many messages as WhatsApp allows.
     * A repeat of the same idempotency key (Meta re-delivers a webhook when the
     * host is slow to ack) is not sent twice.
     *
     * @return array{ok: bool, error?: string, external_id?: string, parts?: int}
     */
    public function send(Answer $answer, OutboundMessage $message): array
    {
        $to = trim((string) ($message->recipient ?? ''));
        if ($to === '') {
            return ['ok' => false, 'error' => 'This conversation has no WhatsApp number to reply to.'];
        }

        // Meta re-delivers the inbound message, not the reply, so the inbound id
        // is the natural key when the host has not set one explicitly.
        $key = trim((string) ($message->idempotencyKey ?? $message->replyToMessageId ?? ''));
        if ($key !== '' && isset($this->delivered[$key])) {
            return $this->delivered[$key];
        }

        $parts = self::chunk(self::format($answer, $this->options), $this->limit());
        if ($parts === []) {
            return ['ok' => false, 'error' => 'There was nothing to send.'];
        }

        $error = null;
        $externalId = null;
        foreach ($parts as $index => $part) {
            $meta = [
                'channel' => $this->id(),
                'thread_id' => $message->threadId,
                'reply_to_message_id' => $message->replyToMessageId,
                'idempotency_key' => $key !== '' ? $key . (count($parts) > 1 ? '#' . ($index + 1) : '') : null,
                'part' => $index + 1,
                'parts' => count($parts),
                'meta' => $message->meta,
            ];

            try {
                $produced = ($this->sender)($to, $part, $meta);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                break;
            }

            [$ok, $partError, $partId] = self::normalize($produced);
            if (!$ok) {
                $error = $partError ?? 'the WhatsApp transport did not accept the message';
                break;
            }
            $externalId = $externalId ?? $partId;
        }

        $result = $error === null
            ? ['ok' => true, 'parts' => count($parts)] + ($externalId !== null ? ['external_id' => $externalId] : [])
            : ['ok' => false, 'error' => $error, 'parts' => count($parts)];

        if ($error === null && $key !== '') {
            $this->delivered[$key] = $result;
        }

        return $result;
    }

    /**
     * Answer → WhatsApp text. Bold becomes `*bold*`, italics `_italics_`, headings
     * become bold lines, links show their URL, and code fences disappear — none of
     * which WhatsApp renders, all of which a customer reads as noise if left in.
     *
     * @param array<string,mixed> $options
     */
    public static function format(Answer $answer, array $options = []): string
    {
        $showSources = self::flag($options, 'show_sources', (bool) Config::get('channels.whatsapp.show_sources', true));
        $maxRows = (int) self::number($options, 'max_rows', (int) Config::get('channels.whatsapp.max_rows_in_message', 15));

        $text = $answer->plain($showSources, max(1, $maxRows));
        $text = self::toWhatsAppMarkup($text);

        if ($answer->pendingAction !== null) {
            $action = (string) ($answer->pendingAction['action'] ?? '');
            if ($action !== '') {
                $text .= "\n\nReply *confirm " . $action . '* to go ahead.';
            }
        }

        return $text;
    }

    /** Markdown the client cannot render → WhatsApp markup, or plain text. */
    public static function toWhatsAppMarkup(string $text): string
    {
        $text = preg_replace('/^#{1,6}[ \t]+(.*?)[ \t]*$/mu', '*$1*', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '_$1_', $text) ?? $text;
        $text = preg_replace('/^```[^\n]*\n?/m', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]\n]+)\]\(([^)\s]+)\)/', '$1: $2', $text) ?? $text;
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Split text into messages of at most $limit characters, breaking between
     * paragraphs and then between lines — a mid-sentence cut is the last resort.
     *
     * @return string[]
     */
    public static function chunk(string $text, int $limit = self::MAX_CHARS): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $limit = $limit > 0 ? $limit : self::MAX_CHARS;
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $parts = [];
        $current = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            foreach (self::splitBlock($block, $limit) as $piece) {
                if ($current === '') {
                    $current = $piece;
                } elseif (mb_strlen($current) + 2 + mb_strlen($piece) <= $limit) {
                    $current .= "\n\n" . $piece;
                } else {
                    $parts[] = $current;
                    $current = $piece;
                }
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        // WhatsApp collapses trailing blank lines, so a part that ends in
        // whitespace would send a message shorter than the split measured.
        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * One inbound WhatsApp webhook into a flat list of customer messages, so a
     * host does not have to walk Meta's nested payload to get the text. Delivery
     * statuses and echoes carry no `from` and are skipped.
     *
     * Also accepts the already-flat shapes hosts use internally
     * (`['from' => …, 'text' => …]` or `['messages' => [[…], …]]`).
     *
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    public static function fromWebhook(array $payload): array
    {
        if (isset($payload['entry'])) {
            $out = [];
            foreach ((array) $payload['entry'] as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    if (($change['field'] ?? 'messages') !== 'messages') {
                        continue;
                    }
                    foreach (self::messagesFromValue((array) ($change['value'] ?? [])) as $message) {
                        $out[] = $message;
                    }
                }
            }

            return $out;
        }

        if (isset($payload['messages'][0]) && is_array($payload['messages'][0])) {
            return array_map(static fn (array $m): array => self::flatMessage($m), (array) $payload['messages']);
        }

        if (isset($payload['from']) || isset($payload['text']) || isset($payload['body'])) {
            return [self::flatMessage($payload)];
        }

        return [];
    }

    /** The message a webhook entry replies to. */
    public static function outboundFor(array $inbound, string $threadId = '', array $meta = []): OutboundMessage
    {
        return new OutboundMessage(
            threadId: $threadId !== '' ? $threadId : (string) ($inbound['conversation_id'] ?? $inbound['from'] ?? ''),
            recipient: (string) ($inbound['from'] ?? ''),
            replyToMessageId: isset($inbound['wamid']) ? (string) $inbound['wamid'] : null,
            idempotencyKey: isset($inbound['wamid']) ? (string) $inbound['wamid'] : null,
            meta: $meta + array_intersect_key($inbound, array_flip(['name', 'type', 'to'])),
        );
    }

    /** Clear the in-process dedupe window (a long-running worker per turn). */
    public function forgetDelivered(): void
    {
        $this->delivered = [];
    }

    // ─── internals ─────────────────────────────────────────────────────────────

    private function limit(): int
    {
        return (int) self::number($this->options, 'max_message_chars', (int) Config::get('channels.whatsapp.max_message_chars', self::MAX_CHARS));
    }

    /** @return array<int,string> pieces of a block that on its own exceeds $limit */
    private static function splitBlock(string $block, int $limit): array
    {
        if (trim($block) === '') {
            return [];
        }
        if (mb_strlen($block) <= $limit) {
            return [$block];
        }

        $parts = [];
        $buffer = '';
        foreach (explode("\n", $block) as $line) {
            if (mb_strlen($line) > $limit) {
                if ($buffer !== '') {
                    $parts[] = $buffer;
                    $buffer = '';
                }
                foreach (mb_str_split($line, $limit) as $slice) {
                    $parts[] = $slice;
                }
                continue;
            }
            $candidate = $buffer === '' ? $line : $buffer . "\n" . $line;
            if (mb_strlen($candidate) <= $limit) {
                $buffer = $candidate;
            } else {
                $parts[] = $buffer;
                $buffer = $line;
            }
        }
        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /** @return array<int,array<string,mixed>> */
    private static function messagesFromValue(array $value): array
    {
        $names = [];
        foreach ((array) ($value['contacts'] ?? []) as $contact) {
            if (isset($contact['wa_id'])) {
                $names[(string) $contact['wa_id']] = (string) ($contact['profile']['name'] ?? '');
            }
        }
        $business = (string) ($value['metadata']['display_phone_number'] ?? $value['metadata']['phone_number'] ?? '');

        $out = [];
        foreach ((array) ($value['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $from = (string) ($message['from'] ?? '');
            $type = (string) ($message['type'] ?? 'text');
            $out[] = [
                'from' => $from,
                'to' => (string) ($message['to'] ?? $business),
                'wamid' => (string) ($message['id'] ?? ''),
                'type' => $type,
                'text' => self::textOf($message, $type),
                'name' => $names[$from] ?? null,
                'timestamp' => (int) ($message['timestamp'] ?? 0),
                'conversation_id' => (string) ($message['conversation']['id'] ?? ''),
                'reply_to' => (string) ($message['context']['id'] ?? ''),
                'raw' => $message,
            ];
        }

        return $out;
    }

    /** The customer's words, wherever this message type keeps them. */
    private static function textOf(array $message, string $type): string
    {
        return match ($type) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['text'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? $message['interactive']['body_text']
                ?? ''
            )),
            'image', 'video', 'document' => trim((string) ($message[$type]['caption'] ?? '')),
            'location' => trim(
                (string) ($message['location']['address'] ?? '') . ' '
                . trim((string) ($message['location']['latitude'] ?? '') . ',' . (string) ($message['location']['longitude'] ?? ''), ',')
            ),
            default => trim((string) (($message[$type] ?? [])['body'] ?? '')),
        };
    }

    /** @param array<string,mixed> $message */
    private static function flatMessage(array $message): array
    {
        $text = trim((string) ($message['text'] ?? $message['body'] ?? $message['message_body'] ?? ''));
        if (is_array($message['text'] ?? null)) {
            $text = trim((string) ($message['text']['body'] ?? ''));
        }

        return [
            'from' => (string) ($message['from'] ?? $message['contact_phone'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'wamid' => (string) ($message['wamid'] ?? $message['wa_message_id'] ?? $message['id'] ?? ''),
            'type' => (string) ($message['type'] ?? 'text'),
            'text' => $text,
            'name' => isset($message['name']) ? (string) $message['name'] : null,
            'timestamp' => (int) ($message['timestamp'] ?? 0),
            'conversation_id' => (string) ($message['conversation_id'] ?? ''),
            'reply_to' => (string) ($message['reply_to'] ?? ''),
            'raw' => $message,
        ];
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private static function normalize(mixed $produced): array
    {
        if (is_bool($produced)) {
            return [$produced, $produced ? null : 'the WhatsApp transport returned false', null];
        }
        if (!is_array($produced)) {
            return [false, 'the WhatsApp transport returned nothing', null];
        }

        $ok = (bool) ($produced['ok'] ?? $produced['success'] ?? false);
        $explicit = isset($produced['ok']) || isset($produced['success']);

        $error = null;
        foreach (['error', 'error_message', 'message'] as $key) {
            if (isset($produced[$key]) && is_scalar($produced[$key]) && trim((string) $produced[$key]) !== '') {
                $error = (string) $produced[$key];
                break;
            }
        }
        if ($error === null && isset($produced['data']['error']['message'])) {
            $error = (string) $produced['data']['error']['message'];
        }

        $external = null;
        foreach (['external_id', 'message_id', 'wamid', 'id'] as $key) {
            if (isset($produced[$key]) && is_scalar($produced[$key]) && (string) $produced[$key] !== '') {
                $external = (string) $produced[$key];
                break;
            }
        }
        if ($external === null) {
            foreach ([['messages'], ['data', 'messages']] as $path) {
                $node = $produced;
                foreach ($path as $step) {
                    $node = is_array($node) ? ($node[$step] ?? null) : null;
                }
                $id = $node[0]['id'] ?? null;
                if (is_scalar($id) && (string) $id !== '') {
                    $external = (string) $id;
                    break;
                }
            }
        }

        // Meta's reply carries no success flag — the message id it hands back *is*
        // the acceptance, so a transport that only echoes Meta's shape counts.
        if (!$explicit && $external !== null && $error === null) {
            $ok = true;
        }

        return [$ok, $ok ? null : $error, $external];
    }

    /** @param array<string,mixed> $options */
    private static function number(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? $default;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** @param array<string,mixed> $options */
    private static function flag(array $options, string $key, bool $default): bool
    {
        return array_key_exists($key, $options) ? (bool) $options[$key] : $default;
    }
}
