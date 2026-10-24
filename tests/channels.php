<?php

/**
 * Channel checks: WhatsApp formatting, the 4096-character split, webhook
 * normalisation, idempotent delivery, and the host transport seam on the legacy
 * service. No network — the "transport" is a closure that records what it got.
 *   php tests/channels.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Channels\WhatsAppChannel;
use ExcelleInsights\AiAssistant\Dto\Answer;
use ExcelleInsights\AiAssistant\Dto\Citation;
use ExcelleInsights\AiAssistant\Dto\OutboundMessage;
use ExcelleInsights\AiAssistant\Services\AiAssistantService;
use ExcelleInsights\AiAssistant\Support\Config;
use PDO;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function same(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $name, callable $run, string $messageContains = ''): void
{
    try {
        $run();
        check($name, false, 'no exception raised');
    } catch (\Throwable $e) {
        check($name, $messageContains === '' || str_contains($e->getMessage(), $messageContains), $e->getMessage());
    }
}

/** @param array<int,array{to:string,body:string,meta:array<string,mixed>}> $sink */
function recording(array &$sink, mixed $reply = true): callable
{
    return static function (string $to, string $body, array $meta) use (&$sink, $reply): mixed {
        $sink[] = ['to' => $to, 'body' => $body, 'meta' => $meta];

        return $reply;
    };
}

function out(array $data = []): OutboundMessage
{
    return OutboundMessage::fromArray($data + [
        'thread_id' => 'conv-42',
        'recipient' => '5511988887777',
        'wa_message_id' => 'wamid.IN1',
    ]);
}

// ─── identity and the shape of the reply ──────────────────────────────────────
$log = [];
$channel = new WhatsAppChannel(recording($log));
same('channel: id is whatsapp', 'whatsapp', $channel->id());
throws('channel: needs a sender', fn () => new WhatsAppChannel('not a callable at all'), 'sender callable');

$sent = $channel->send(new Answer(text: 'Your oil change is *confirmed* for Friday.'), out());
same('send: ok', true, $sent['ok'] ?? false);
same('send: one message', 1, count($log));
same('send: to the customer', '5511988887777', $log[0]['to']);
same('send: the body', 'Your oil change is *confirmed* for Friday.', $log[0]['body']);
same('send: the thread travels', 'conv-42', $log[0]['meta']['thread_id']);
same('send: reply-to travels', 'wamid.IN1', $log[0]['meta']['reply_to_message_id']);
same('send: part 1 of 1', 1, ($log[0]['meta']['part'] ?? 0) + 0);
same('send: the key is the inbound id', 'wamid.IN1', $log[0]['meta']['idempotency_key']);

// ─── the transports hosts already have ───────────────────────────────────────
$succeeded = [];
same('reply: bool true sends', true, (new WhatsAppChannel(recording($succeeded)))->send(new Answer(text: 'hi'), out())['ok']);
same('reply: legacy success key', true, (new WhatsAppChannel(recording($succeeded, ['success' => true])))->send(new Answer(text: 'hi'), out())['ok']);
$metaReply = (new WhatsAppChannel(static fn (): array => ['success' => true, 'data' => ['messages' => [['id' => 'wamid.OUT9']]]]))
    ->send(new Answer(text: 'hi'), out());
same('reply: meta response id found', 'wamid.OUT9', $metaReply['external_id'] ?? '');

$refused = (new WhatsAppChannel(static fn (): array => ['ok' => false, 'error' => '131047 Recipient not in allowed list']))
    ->send(new Answer(text: 'hi'), out());
same('reply: refused is a failure', false, $refused['ok']);
check('reply: the reason survives', str_contains((string) $refused['error'], 'not in allowed list'), json_encode($refused));

$raw = (new WhatsAppChannel(static fn (): array => ['success' => false, 'data' => ['error' => ['message' => 'Invalid credentials']]]))
    ->send(new Answer(text: 'hi'), out());
check('reply: nested meta error surfaced', str_contains((string) $raw['error'], 'Invalid credentials'), json_encode($raw));

$boom = (new WhatsAppChannel(static function (): void { throw new RuntimeException('socket timed out'); }))->send(new Answer(text: 'hi'), out());
same('transport throws: reported', false, $boom['ok']);
same('transport throws: the message', 'socket timed out', $boom['error'] ?? '');

$noNumber = (new WhatsAppChannel(recording($succeeded)))->send(new Answer(text: 'hi'), new OutboundMessage(threadId: 't'));
same('send: without a number nothing is sent', false, $noNumber['ok'] ?? null);
$nothing = (new WhatsAppChannel(recording($succeeded)))->send(new Answer(text: '   '), out());
check('send: an empty answer is not an empty message', ($nothing['ok'] ?? null) === false && str_contains((string) $nothing['error'], 'nothing'), json_encode($nothing));

// ─── retries must not double-reply ───────────────────────────────────────────
$retries = [];
$once = new WhatsAppChannel(recording($retries));
$once->send(new Answer(text: 'First reply.'), out(['idempotency_key' => 'wamid.SAME']));
$again = $once->send(new Answer(text: 'First reply.'), out(['idempotency_key' => 'wamid.SAME']));
same('dedupe: the webhook re-delivery sends once', 1, count($retries));
same('dedupe: the second call still looks delivered', true, $again['ok'] ?? false);
$once->forgetDelivered();
$once->send(new Answer(text: 'First reply.'), out(['idempotency_key' => 'wamid.SAME']));
same('dedupe: cleared for the next process', 2, count($retries));
$once->send(new Answer(text: 'Different question.'), out(['idempotency_key' => 'wamid.OTHER']));
same('dedupe: a new message is a new send', 3, count($retries));

// ─── markdown a phone cannot render ─────────────────────────────────────────
$text = WhatsAppChannel::format(new Answer(text: "## Price list\n\n**Oil change** is _popular_.\nSee [the terms](https://ex.am/terms).\n```\nSELECT 1\n```\nDone."));
check('markup: heading becomes bold', str_contains($text, '*Price list*'), $text);
check('markup: bold is single asterisks', str_contains($text, '*Oil change*'), $text);
check('markup: italics survive', str_contains($text, '_popular_'), $text);
check('markup: link shows its url', str_contains($text, 'the terms: https://ex.am/terms'), $text);
check('markup: no fence characters', !str_contains($text, '```'), $text);
check('markup: no hash headings left', !str_contains($text, '##'), $text);

$rows = new Answer(
    text: 'Your last 3 orders:',
    intent: 'data',
    rows: [['id' => 9, 'total' => '240.00'], ['id' => 10, 'total' => '80.00']],
    citations: [new Citation('record', 'Order 9', '9'), new Citation('knowledge', 'Price list', 'k1')],
);
$withSources = WhatsAppChannel::format($rows);
check('format: rows become lines', str_contains($withSources, '- id: 9 | total: 240.00'), $withSources);
check('format: sources are named', str_contains($withSources, 'Sources: Order 9; Price list'), $withSources);
$without = WhatsAppChannel::format($rows, ['show_sources' => false]);
check('format: sources can be off', !str_contains($without, 'Sources:'), $without);
$capped = WhatsAppChannel::format(new Answer(text: 'rows', rows: [['n' => 1], ['n' => 2], ['n' => 3]]), ['max_rows' => 2]);
check('format: long tables are truncated', str_contains($capped, '1 more rows'), $capped);

$pending = WhatsAppChannel::format(new Answer(text: 'I can create that lead.', intent: 'action', pendingAction: ['action' => 'create_lead']));
check('format: a proposal says how to confirm', str_contains($pending, 'Reply *confirm create_lead*'), $pending);

// ─── 4096 characters ────────────────────────────────────────────────────────
$short = WhatsAppChannel::chunk('one short message');
same('chunk: short text is one message', 1, count($short));
same('chunk: empty text is none', 0, count(WhatsAppChannel::chunk('   ')));

$paragraphs = trim(str_repeat("Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod.\n\n", 120));
$parts = WhatsAppChannel::chunk($paragraphs, 400);
check('chunk: every part fits', $parts !== [] && max(array_map('mb_strlen', $parts)) <= 400, (string) max(array_map('mb_strlen', $parts)));
check('chunk: nothing is lost', str_replace([' ', "\n"], '', implode('', $parts)) === str_replace([' ', "\n"], '', $paragraphs), implode('|', array_map('mb_strlen', $parts)));
check('chunk: packs whole paragraphs, cut at their edges', str_ends_with($parts[0], '.') && substr_count($parts[0], "\n\n") >= 1, (string) $parts[0]);
check('chunk: a part never ends in whitespace', $parts === array_map('trim', $parts), json_encode(array_map(static fn (string $p): string => substr($p, -1), $parts)));

$oneline = str_repeat('word ', 500);
$hard = WhatsAppChannel::chunk($oneline, 100);
check('chunk: one long line is cut anyway', $hard !== [] && max(array_map('mb_strlen', $hard)) <= 100, (string) count($hard));

$longAnswer = new WhatsAppChannel(recording($retries), ['max_message_chars' => 120]);
$big = $longAnswer->send(new Answer(text: 'Intro.' . "\n\n" . $paragraphs), out(['idempotency_key' => 'wamid.BIG']));
same('send: a long answer is several messages', true, $big['ok'] ?? false);
check('send: reports the parts', ($big['parts'] ?? 1) > 1, json_encode($big));
$suffixes = array_map(static fn (array $m): string => (string) $m['meta']['idempotency_key'], array_slice($retries, -($big['parts'] ?? 1)));
check('send: each part has its own key', in_array('wamid.BIG#1', $suffixes, true) && in_array('wamid.BIG#2', $suffixes, true), json_encode($suffixes));

// ─── inbound: Meta's nested webhook, and a host's flat one ──────────────────
$payload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => '111',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '5511888887777', 'phone_number' => '5511888887777'],
                'contacts' => [['profile' => ['name' => 'Maria Silva'], 'wa_id' => '5511988887777']],
                'messages' => [
                    ['from' => '5511988887777', 'id' => 'wamid.A', 'type' => 'text', 'timestamp' => '1730000000',
                     'text' => ['body' => 'How much is an oil change?'], 'to' => '5511888887777'],
                    ['from' => '5511988887777', 'id' => 'wamid.B', 'type' => 'image', 'image' => ['caption' => 'my invoice'], 'to' => '5511888887777'],
                    ['from' => '5511988887777', 'id' => 'wamid.C', 'type' => 'interactive',
                     'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'Yes, book it']],
                     'context' => ['id' => 'wamid.A'], 'to' => '5511888887777'],
                ],
            ],
        ], [
            'field' => 'messages',
            'value' => ['statuses' => [['id' => 'wamid.A', 'status' => 'delivered']]],
        ]],
    ]],
];
$inbound = WhatsAppChannel::fromWebhook($payload);
same('webhook: three customer messages', 3, count($inbound));
same('webhook: text read', 'How much is an oil change?', $inbound[0]['text']);
same('webhook: from and wamid', '5511988887777', $inbound[0]['from']);
same('webhook: contact name resolved', 'Maria Silva', $inbound[0]['name']);
same('webhook: caption is the text', 'my invoice', $inbound[1]['text']);
same('webhook: image type kept', 'image', $inbound[1]['type']);
same('webhook: button reply read', 'Yes, book it', $inbound[2]['text']);
same('webhook: reply-to kept', 'wamid.A', $inbound[2]['reply_to']);
$statusOnly = [
    'entry' => [[
        'changes' => [[
            'value' => ['statuses' => [['id' => 'wamid.A', 'status' => 'read']]],
        ]],
    ]],
];
same('webhook: delivery statuses are not messages', 0, count(WhatsAppChannel::fromWebhook($statusOnly)));

$flat = WhatsAppChannel::fromWebhook(['from' => '5521', 'text' => 'hello', 'wa_message_id' => 'm1', 'conversation_id' => '88']);
same('webhook: a host-flat message works', 'hello', $flat[0]['text']);
same('webhook: flat conversation id', '88', $flat[0]['conversation_id']);
$list = WhatsAppChannel::fromWebhook(['messages' => [['from' => '5531', 'body' => 'price?'], ['from' => '5532', 'body' => 'hi']]]);
same('webhook: a list of messages', 2, count($list));
same('webhook: body key read', 'price?', $list[0]['text']);
same('webhook: an unrelated payload yields nothing', 0, count(WhatsAppChannel::fromWebhook(['object' => 'nothing'])));

$reply = WhatsAppChannel::outboundFor($inbound[0], 'host-conv-7');
same('outbound: thread from the host', 'host-conv-7', $reply->threadId);
same('outbound: recipient is the sender', '5511988887777', $reply->recipient);
same('outbound: idempotency is the wamid', 'wamid.A', $reply->idempotencyKey);
same('outbound: reply-to is the wamid', 'wamid.A', $reply->replyToMessageId);
check('outbound: the name is available to the host', ($reply->meta['name'] ?? '') === 'Maria Silva', json_encode($reply->meta));
$fallback = WhatsAppChannel::outboundFor(['from' => '5541', 'wamid' => 'w']);
same('outbound: no host thread falls back to the number', '5541', $fallback->threadId);

// ─── the legacy service uses the host transport when given one ───────────────
function sqliteWa(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE whatsapp_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER, direction TEXT, message_body TEXT, message_type TEXT, delivery_status TEXT, sent_at TEXT, author_id INTEGER)');
    $pdo->exec('CREATE TABLE whatsapp_conversations (conversation_id INTEGER PRIMARY KEY, last_message_at TEXT)');
    $pdo->exec("INSERT INTO whatsapp_conversations (conversation_id, last_message_at) VALUES (5, '2026-10-24 00:00:00')");

    return $pdo;
}

function dispatch(AiAssistantService $service, string $to, string $body, int $conversationId = 5): array
{
    $method = new ReflectionMethod(AiAssistantService::class, 'sendText');
    $method->setAccessible(true);

    return (array) $method->invoke($service, $conversationId, $to, $body);
}

$transportLog = [];
$legacy = new AiAssistantService(sqliteWa());
same('legacy: setTransport is chainable', $legacy, $legacy->setTransport(recording($transportLog, ['ok' => true, 'message_id' => 'wamid.OUT2'])));
[$ok, $error] = dispatch($legacy, '5511988887777', 'Your booking is confirmed.');
same('legacy: the transport sends', true, $ok);
same('legacy: no error', null, $error);
same('legacy: the transport got the text', 'Your booking is confirmed.', $transportLog[0]['body']);
same('legacy: the transport knows the conversation', 5, $transportLog[0]['meta']['conversation_id'] ?? null);

$logged = sqliteWa();
$logged->exec('DELETE FROM whatsapp_messages');
$withLog = new AiAssistantService($logged);
$withLog->setTransport(static fn (): array => ['ok' => true]);
dispatch($withLog, '5511', 'Logged reply');
same('legacy: the reply is recorded for the host', 'sent', (string) $logged->query('SELECT delivery_status FROM whatsapp_messages')->fetchColumn());
same('legacy: the conversation moves on', true, (bool) $logged->query("SELECT last_message_at FROM whatsapp_conversations WHERE conversation_id = 5 AND last_message_at != '2026-10-24 00:00:00'")->fetchColumn());

$failing = new AiAssistantService(sqliteWa());
$failing->setTransport(static fn (): array => ['ok' => false, 'error' => 'rate limited']);
[$fOk, $fErr] = dispatch($failing, '5511', 'Hi');
same('legacy: a refusal is reported', false, $fOk);
same('legacy: with the host reason', 'rate limited', $fErr);

$thrown = new AiAssistantService(sqliteWa());
$thrown->setTransport(static function (): void { throw new RuntimeException('transport exploded'); });
[$tOk, $tErr] = dispatch($thrown, '5511', 'Hi');
same('legacy: a throwing transport is a failure', false, $tOk);
same('legacy: and never a crash', 'transport exploded', $tErr);

// A host that keeps no whatsapp_* tables must still be able to reply: the log
// insert is allowed to fail, the send is not.
$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tableless = new AiAssistantService($bare);
$tableless->setTransport(static fn (): bool => true);
$errLog = tempnam(sys_get_temp_dir(), 'ai-channels-test');
$previousLog = ini_get('error_log');
ini_set('error_log', (string) $errLog);
[$bareOk] = dispatch($tableless, '5511', 'No tables here');
ini_set('error_log', (string) $previousLog);
same('legacy: a missing log table does not lose the reply', true, $bareOk);
check('legacy: but the host is told', str_contains((string) file_get_contents((string) $errLog), 'recordOutbound'), (string) $errLog);
@unlink((string) $errLog);

$noTransport = new AiAssistantService(sqliteWa());
same('legacy: the transport can be taken back', $noTransport, $noTransport->setTransport(null));

Config::reset();
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
