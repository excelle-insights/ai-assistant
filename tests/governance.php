<?php

/**
 * Pure-logic test harness for the AI assistant governance services.
 *
 *   php tests/governance.php
 *
 * Runs with NO database and NO network: every service is constructed with a null
 * PDO and its storage access is swapped for an in-memory seam via the subclasses
 * at the bottom of this file. Prints "N passed, M failed" and exits non-zero when
 * any assertion fails, so it is safe to wire into CI.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use ExcelleInsights\AiAssistant\Contracts\AuditSinkInterface;
use ExcelleInsights\AiAssistant\Dto\AuditEvent;
use ExcelleInsights\AiAssistant\Services\AuditService;
use ExcelleInsights\AiAssistant\Services\FeedbackService;
use ExcelleInsights\AiAssistant\Services\ReportService;
use ExcelleInsights\AiAssistant\Services\SchedulesService;
use ExcelleInsights\AiAssistant\Services\SettingsService;
use ExcelleInsights\AiAssistant\Services\UsageService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Cron;
use ExcelleInsights\AiAssistant\Support\Db;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

// ── harness ──────────────────────────────────────────────────────────────────
final class Harness
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];

    public static function ok(bool $condition, string $label): void
    {
        if ($condition) {
            self::$passed++;
        } else {
            self::$failed++;
            self::$failures[] = $label;
            echo "  FAIL: {$label}\n";
        }
    }

    public static function eq(mixed $actual, mixed $expected, string $label): void
    {
        $ok = $actual === $expected;
        self::ok($ok, $label . ($ok ? '' : " (expected " . self::dump($expected) . ", got " . self::dump($actual) . ")"));
    }

    public static function throws(callable $fn, string $label): void
    {
        try {
            $fn();
            self::ok(false, $label . ' (no exception thrown)');
        } catch (Throwable) {
            self::ok(true, $label);
        }
    }

    private static function dump(mixed $v): string
    {
        if (is_scalar($v) || $v === null) {
            return var_export($v, true);
        }
        return json_encode($v);
    }
}

function section(string $name): void
{
    echo "\n== {$name} ==\n";
}

// ── test doubles (in-memory storage seams; no PDO) ───────────────────────────

final class RecordingSink implements AuditSinkInterface
{
    /** @var AuditEvent[] */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function query(array $filters = [], int $limit = 100): array
    {
        return [];
    }
}

final class CaptureAudit extends AuditService
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    protected function writeRow(array $row): void
    {
        $this->rows[] = $row;
    }
}

final class ThrowingAudit extends AuditService
{
    protected function writeRow(array $row): void
    {
        throw new RuntimeException('simulated store failure');
    }
}

final class MemorySettings extends SettingsService
{
    /** @var array<string,array<string,mixed>> */
    public array $store = [];

    private function key(int $c, string $s, string $k, string $n): string
    {
        return "{$c}|{$s}|{$k}|{$n}";
    }

    protected function readRow(int $companyId, string $scope, string $scopeKey, string $key): ?array
    {
        return $this->store[$this->key($companyId, $scope, $scopeKey, $key)] ?? null;
    }

    protected function writeRow(array $row): bool
    {
        $row['updated_at'] ??= Db::now();
        $this->store[$this->key((int) $row['company_id'], (string) $row['scope'], (string) $row['scope_key'], (string) $row['key_name'])] = $row;
        return true;
    }

    protected function deleteRow(int $companyId, string $scope, string $scopeKey, string $key): bool
    {
        $k = $this->key($companyId, $scope, $scopeKey, $key);
        if (isset($this->store[$k])) {
            unset($this->store[$k]);
            return true;
        }
        return false;
    }

    protected function listRows(int $companyId, string $scope, string $scopeKey): array
    {
        return array_values(array_filter(
            $this->store,
            static fn (array $r): bool => (int) $r['company_id'] === $companyId
                && (string) $r['scope'] === $scope
                && (string) $r['scope_key'] === $scopeKey
        ));
    }

    protected function candidateRows(int $companyId, ?int $userId): array
    {
        return array_values($this->store);
    }
}

final class FakeCounterUsage extends UsageService
{
    public int $fakeUsed = 0;

    protected function readUsed(string $dimension, string $key, string $window, DateTimeInterface $now): int
    {
        return $this->fakeUsed;
    }
}

final class StubbedReport extends ReportService
{
    /** @var array<string,mixed>|null */
    public ?array $row = null;

    protected function loadDefinition(string $id, int $companyId): ?array
    {
        if ($this->row === null) {
            return null;
        }
        $row = $this->row;
        $row['id'] = $id;
        $row['company_id'] = $companyId;
        return $row;
    }
}

// ── Cron ─────────────────────────────────────────────────────────────────────
section('Cron');

$utc = new DateTimeZone('UTC');
$at = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s, $utc);

Harness::ok(Cron::isValid('* * * * *'), 'isValid wildcard');
Harness::ok(Cron::isValid('*/15 * * * *'), 'isValid */15');
Harness::ok(Cron::isValid('0 9 * * 1-5'), 'isValid weekday');
Harness::ok(Cron::isValid('30 6 1 * *'), 'isValid monthly');
Harness::ok(!Cron::isValid('bogus'), 'reject garbage');
Harness::ok(!Cron::isValid('* * * *'), 'reject 4 fields');
Harness::ok(!Cron::isValid('99 * * * *'), 'reject minute out of range');
Harness::ok(!Cron::isValid('* * * 13 *'), 'reject month 13');
Harness::ok(!Cron::isValid('* * * * 8'), 'reject dow 8');
Harness::ok(!Cron::isValid('5-3 * * * *'), 'reject reversed range');
Harness::ok(!Cron::isValid('*/0 * * * *'), 'reject zero step');
Harness::ok(Cron::isValid('0 0 * jan-mar *'), 'isValid month names');
Harness::ok(Cron::isValid('0 0 * * MON-FRI'), 'isValid day names');

// */15
Harness::ok(Cron::matches('*/15 * * * *', $at('2026-01-01 00:00:00')), '*/15 matches :00');
Harness::ok(Cron::matches('*/15 * * * *', $at('2026-01-01 00:45:00')), '*/15 matches :45');
Harness::ok(!Cron::matches('*/15 * * * *', $at('2026-01-01 00:07:00')), '*/15 skips :07');

// weekday 9am
Harness::ok(Cron::matches('0 9 * * 1-5', $at('2026-01-05 09:00:00')), 'weekday 9am matches Mon');
Harness::ok(!Cron::matches('0 9 * * 1-5', $at('2026-01-03 09:00:00')), 'weekday 9am skips Sat');
Harness::ok(!Cron::matches('0 9 * * 1-5', $at('2026-01-05 08:00:00')), 'weekday skips wrong hour');

// monthly, name aliases
Harness::ok(Cron::matches('30 6 1 * *', $at('2026-05-01 06:30:00')), 'monthly matches 1st');
Harness::ok(Cron::matches('0 0 * mar *', $at('2026-03-10 00:00:00')), 'month name matches');
Harness::ok(!Cron::matches('0 0 * mar *', $at('2026-04-10 00:00:00')), 'month name skips');

// day-of-month XOR day-of-week (both restricted -> union)
// 2026-01-30 is a Friday; 2026-01-13 is a Tuesday.
Harness::ok(Cron::matches('0 0 13 * 5', $at('2026-01-13 00:00:00')), 'XOR: matches the 13th');
Harness::ok(Cron::matches('0 0 13 * 5', $at('2026-01-30 00:00:00')), 'XOR: matches Friday');
Harness::ok(!Cron::matches('0 0 13 * 5', $at('2026-01-14 00:00:00')), 'XOR: 14th Wed matches neither');
// when dom is a wildcard the rule is AND (dow-only)
Harness::ok(!Cron::matches('0 0 * * 1', $at('2026-01-06 00:00:00')), 'wildcard dom -> dow-only AND skips Tue');

// next()
$n = Cron::next('0 9 * * 1-5', $at('2026-01-05 09:00:00'), $utc);
Harness::eq($n?->format('Y-m-d H:i'), '2026-01-06 09:00', 'next is strictly-after (Tue)');
Harness::eq(
    Cron::next('0 0 29 2 *', $at('2024-03-01 00:00:00'), $utc)?->format('Y-m-d'),
    '2028-02-29',
    'next leap Feb 29'
);
// a "day 31" expression whose month cannot host a 31st rolls on to the next month that can
Harness::eq(Cron::next('0 0 31 4 *', $at('2026-01-01 00:00:00'), $utc)?->format('Y-m-d'), '2026-05-31', 'skips 30-day April');
Harness::ok(Cron::next('0 0 30 2 *', $at('2026-01-01 00:00:00'), $utc) === null, 'impossible Feb 30 -> null');

// step-on-range
Harness::ok(Cron::matches('0 8-18/4 * * *', $at('2026-01-01 12:00:00')), 'step-on-range matches 12');
Harness::ok(!Cron::matches('0 8-18/4 * * *', $at('2026-01-01 13:00:00')), 'step-on-range skips 13');

// DST safety: NY spring-forward 2026-03-08 02:00 does not exist, so 02:30 that day
// fires at the next real instant (03:30) rather than being dropped until the next day.
$ny = new DateTimeZone('America/New_York');
$dstNext = Cron::next('30 2 * * *', new DateTimeImmutable('2026-03-07 03:00:00', $ny), $ny);
Harness::ok($dstNext !== null && $dstNext->format('H:i') === '03:30', 'DST spring-forward: 02:30 skipped to 03:30 next day');
Harness::eq($dstNext?->format('Y-m-d'), '2026-03-08', 'DST skips to the following day');

// ── AuditService (pure) ──────────────────────────────────────────────────────
section('AuditService');

Config::reset();
Config::set('audit.record_sql', false);
$maskedAudit = new AuditService(null, null, new SchemaPolicy());
$row = $maskedAudit->normalize([
    'company_id' => 5,
    'user_id' => '9',
    'event' => 'chat',
    'question' => 'contact bob@example.com or +254712345678 please',
    'sql' => 'SELECT * FROM t WHERE id = 1',
]);
Harness::ok($row['sql_text'] === null, 'record_sql=false blanks sql_text');
Harness::ok(is_string($row['query_hash']) && strlen($row['query_hash']) === 64, 'query always hashed');
Harness::ok(str_contains($row['question'] ?? '', 'b***@example.com'), 'email masked before store');
Harness::ok(str_contains($row['question'] ?? '', '254') && !str_contains($row['question'] ?? '', '254712345678'), 'phone digits masked');

// hash stability across whitespace/case
$h1 = $maskedAudit->normalize(['sql' => 'SELECT   1  FROM t'])['query_hash'];
$h2 = $maskedAudit->normalize(['sql' => 'select 1 from t'])['query_hash'];
Harness::ok($h1 === $h2, 'hash stable across case/whitespace');
Harness::eq($h1, AuditEvent::hashQuery('select 1 from t'), 'hash matches AuditEvent::hashQuery');

// no policy -> plain (no masking), sql kept when record_sql true
Config::set('audit.record_sql', true);
$plain = new AuditService(null);
$plainRow = $plain->normalize(['question' => 'bob@example.com', 'sql' => 'SELECT 1']);
Harness::ok($plainRow['question'] === 'bob@example.com', 'no policy -> question untouched');
Harness::ok($plainRow['sql_text'] === 'SELECT 1', 'record_sql=true keeps sql');

// correlation id generated + returned by log()
$correlation = $plain->log(['company_id' => 1, 'user_id' => 'x'], 'chat', ['detail' => ['k' => 'v']]);
Harness::ok(is_string($correlation) && strlen($correlation) >= 16, 'log() returns an event id');

// buildWhere -> parameterized fragments (no interpolated user values)
[$where, $bind] = $plain->buildWhere([
    'company_id' => 3,
    'status' => 'ok',
    'events' => ['chat', 'export'],
    'from' => '2026-01-01',
    'to' => '2026-01-31',
]);
$joined = implode(' AND ', $where);
Harness::ok(str_contains($joined, '`company_id` = :f'), 'company filter parameterized');
Harness::ok(str_contains($joined, '`event` IN ('), 'events filter is an IN list');
// one placeholder per bound value: company + status + 2 event list entries + from + to
Harness::ok(count($bind) === 6, 'six bound params (IN list adds one per value)');
Harness::ok(!str_contains($joined, "'"), 'no quoted literals in WHERE (all bound)');
Harness::eq($bind[':f4'] ?? null, '2026-01-01 00:00:00', 'from normalised to start of day');
Harness::eq($bind[':f5'] ?? null, '2026-01-31 23:59:59', 'to normalised to end of day');

// sink precedence
Config::reset();
$sink = new RecordingSink();

Config::set('audit.store', 'sink');
$onlySink = new CaptureAudit(null);
$onlySink->sink($sink);
$onlySink->emit(['company_id' => 1, 'event' => 'chat', 'sql' => 'SELECT 1']);
Harness::eq(count($sink->events), 1, 'store=sink: sink received the event');
Harness::eq(count($onlySink->rows), 0, 'store=sink: table NOT written');

$sink->events = [];
Config::set('audit.store', 'table');
$tableOnly = new CaptureAudit(null);
$tableOnly->emit(['company_id' => 1, 'event' => 'chat']);
Harness::eq(count($sink->events), 0, 'store=table (no sink): sink untouched');
Harness::eq(count($tableOnly->rows), 1, 'store=table: row written to table seam');

$sink->events = [];
Config::set('audit.store', 'both');
$both = new CaptureAudit(null);
$both->sink($sink);
$both->emit(['company_id' => 1, 'event' => 'chat']);
Harness::eq(count($sink->events), 1, 'store=both: sink written');
Harness::eq(count($both->rows), 1, 'store=both: table also written');

// the write path must never throw, even when the store does
$captured = null;
$boom = new ThrowingAudit(null, function (Throwable $e) use (&$captured): void {
    $captured = $e->getMessage();
});
Config::set('audit.store', 'table');
$boom->emit(['company_id' => 1, 'event' => 'chat']);
$boomId = $boom->log(['company_id' => 2], 'export');
Harness::ok($captured === 'simulated store failure', 'store failure routed to onError');
Harness::ok(is_string($boomId) && $boomId !== '', 'log() still returns an id after a failure');
Harness::ok($boom->exportCsv(['company_id' => 1]) === '', 'exportCsv returns string with no db');
Harness::ok($boom->verify() === false, 'verify() false without a database');

// ── UsageService (pure limits) ───────────────────────────────────────────────
section('UsageService');

Config::reset();
$usage = new UsageService(null);
$stamp = new DateTimeImmutable('2026-10-24 10:07:33', $utc);

Harness::eq($usage->windowBounds('minute', $stamp)['reset_at'], '2026-10-24 10:08:00', 'minute window resets next minute');
Harness::eq($usage->windowBounds('hour', $stamp)['reset_at'], '2026-10-24 11:00:00', 'hour window resets next hour');
Harness::eq($usage->windowBounds('day', $stamp)['reset_at'], '2026-10-25 00:00:00', 'day window resets midnight');
Harness::eq($usage->windowBounds('month', $stamp)['reset_at'], '2026-11-01 00:00:00', 'month window resets 1st');

// boundary at exactly the limit -> denied
Harness::ok($usage->limitMath('user', 'minute', 4, 5, $stamp)['allowed'] === true, 'under limit allowed');
Harness::ok($usage->limitMath('user', 'minute', 5, 5, $stamp)['allowed'] === false, 'at limit denied');
Harness::ok($usage->limitMath('user', 'minute', 6, 5, $stamp)['allowed'] === false, 'over limit denied');
Harness::ok($usage->limitMath('ip', 'day', 9999, 0, $stamp)['allowed'] === true, 'limit 0 = unlimited');

// injected fake counter drives checkLimit
$fake = new FakeCounterUsage(null);
Config::set('rate_limit.per_minute', 3);
$fake->fakeUsed = 3;
$decision = $fake->checkLimit('user', 'bob', 'minute');
Harness::ok($decision['allowed'] === false && $decision['used'] === 3 && $decision['limit'] === 3, 'checkLimit honours injected counter at boundary');
$fake->fakeUsed = 1;
Harness::ok($fake->checkLimit('user', 'bob', 'minute')['allowed'] === true, 'checkLimit allows below boundary');

// self-contained in-memory counters when no persistent table exists
Config::reset();
Config::set('rate_limit.per_minute', 2);
$mem = new UsageService(null);
Harness::eq($mem->consume('user', 'alice', 'minute')['used'], 1, 'memory counter increments (1st)');
Harness::eq($mem->consume('user', 'alice', 'minute')['used'], 2, 'memory counter increments (2nd)');
Harness::ok($mem->consume('user', 'alice', 'minute')['allowed'] === false, 'memory counter denies at limit');
Harness::ok($mem->consume('user', 'bob', 'minute')['used'] === 1, 'counter is per key');

// configured limit resolution
Config::set('rate_limit.per_hour', 60);
Harness::eq($usage->configuredLimit('user', 'hour'), 60, 'configuredLimit reads rate_limit.per_hour');

// normalizeUsage folds aliases onto counters
$norm = $usage->normalizeUsage(['company_id' => 7, 'user_id' => 3, 'chat' => 1, 'tokens_in' => 10, 'tokens_out' => 5, 'cost' => 0.125]);
Harness::eq($norm['counters']['chats'], 1, 'chat alias -> chats');
Harness::eq($norm['counters']['tokens_in'], 10, 'tokens_in captured');
Harness::eq($norm['cost'], 0.125, 'cost rounded');
Harness::eq($norm['company_id'], 7, 'company id kept');

// ── SettingsService (scope precedence + round-trip) ──────────────────────────
section('SettingsService');

Config::reset();
$settings = new MemorySettings(null);

// JSON encode/decode round trip for assorted value shapes
foreach ([42, 'hi', [1, 2, 3], ['a' => 1], false, null, 0.5, 0] as $value) {
    $rt = $settings->decodeValue($settings->encodeValue($value));
    Harness::ok($rt == $value, 'round-trip ' . json_encode($value));
}

// exact-scope get/put/forget
$settings->put('tone', 'GLOBAL', ['scope' => 'global']);
$settings->put('tone', 'COMPANY', ['scope' => 'company', 'company_id' => 7]);
$settings->put('tone', 'USER', ['scope' => 'user', 'company_id' => 7, 'scope_key' => '42']);
Harness::eq($settings->get('tone', null, ['scope' => 'global']), 'GLOBAL', 'global scope read-back');
Harness::eq($settings->get('tone', null, ['scope' => 'company', 'company_id' => 7]), 'COMPANY', 'company scope read-back');
Harness::eq($settings->get('tone', null, ['scope' => 'user', 'company_id' => 7, 'scope_key' => '42']), 'USER', 'user scope read-back');
Harness::eq($settings->get('missing', 'fallback', ['scope' => 'global']), 'fallback', 'missing key returns default');

// merge precedence global < company < user
$merged = $settings->mergeConfig(7, 42);
Harness::eq($merged['tone'] ?? null, 'USER', 'user wins in merged map');
Harness::eq($settings->mergeConfig(7, null)['tone'] ?? null, 'COMPANY', 'company wins with no user');
Harness::eq($settings->mergeConfig(99, null)['tone'] ?? null, 'GLOBAL', 'foreign tenant only sees global');

// allFor + forget
$settings->put('limit_rows', 50, ['scope' => 'company', 'company_id' => 7]);
$all = $settings->allFor(['scope' => 'company', 'company_id' => 7]);
Harness::ok(($all['tone'] ?? null) === 'COMPANY' && ($all['limit_rows'] ?? null) === 50, 'allFor returns the scope map');
$settings->forget('tone', ['scope' => 'company', 'company_id' => 7]);
Harness::eq($settings->get('tone', null, ['scope' => 'company', 'company_id' => 7]), null, 'forget removes the value');

// pure helpers
Harness::eq($settings->scopeRank('global'), 0, 'scopeRank global');
Harness::eq($settings->scopeRank('user'), 2, 'scopeRank user');
Harness::ok($settings->isSecretKey('llm.api_key'), 'isSecretKey flags api_key');

// ── FeedbackService (pure) ───────────────────────────────────────────────────
section('FeedbackService');

$fb = new FeedbackService(null);
Harness::eq($fb->normaliseRating('thumbs_up'), 'up', 'thumbs_up -> up');
Harness::eq($fb->normaliseRating('DOWN'), 'down', 'case-insensitive down');
Harness::eq($fb->normaliseRating('5'), '5', 'numeric scale kept');
Harness::ok($fb->isHelpful('up') && $fb->isHelpful('5') && !$fb->isHelpful('down'), 'isHelpful mapping');

$sub = $fb->normaliseSubmit(['message_id' => 'm1', 'question' => 'q'], 'up', 'wrong', 'c', null);
Harness::eq($sub['message_id'], 'm1', 'submit keeps message id');
Harness::eq($sub['status'], 'pending', 'new feedback is pending');

// the two inputs differ only in case and repeated spaces, i.e. only pre-normalisation,
// so the stored hash must be identical (that is what makes re-promotion idempotent) —
// while a genuinely different answer still hashes differently.
$ha = $fb->contentHash('What are hours?', 'We  open at 8am');
$hb = $fb->contentHash('what are hours? ', 'we open at 8am');
Harness::ok(
    $ha === $hb && $ha !== $fb->contentHash('What are hours?', 'We open at 9am'),
    'content hash normalises case + whitespace yet distinguishes a changed answer'
);
$hc = $fb->contentHash('What are hours?', 'We open at 8am');
$hd = $fb->contentHash('what  are  hours?', 'we  open  at  8am');
Harness::ok($hc === $hd, 'content hash stable across case + whitespace');

// ── ReportService (pure) ─────────────────────────────────────────────────────
section('ReportService');

Config::reset();
Config::set('reports.allowed_datasets', ['sales', 'inventory']);
$report = new ReportService(null);

Harness::eq($report->validateDefinition(['intent' => 'data_qa', 'chart' => 'bar', 'dataset' => 'sales'], 'Sales'), [], 'valid definition accepted');
Harness::ok(in_array("unknown chart type 'sparkline'", $report->validateDefinition(['intent' => 'data_qa', 'chart' => 'sparkline', 'dataset' => 'sales'], 'X'), true), 'unknown chart type rejected');
Harness::ok(in_array("unknown dataset 'ghost'", $report->validateDefinition(['intent' => 'data_qa', 'chart' => 'bar', 'dataset' => 'ghost'], 'X'), true), 'unknown dataset rejected');
Harness::ok(in_array('definition.intent is required', $report->validateDefinition(['chart' => 'bar'], 'X'), true), 'missing intent rejected');
Harness::ok(in_array('report name is required', $report->validateDefinition(['intent' => 'data_qa'], ''), true), 'missing name rejected');

$compiled = $report->compileRequest(
    ['intent' => 'data_qa', 'chart' => 'pie', 'dataset' => 'sales', 'columns' => ['region'], 'filters' => ['year' => 2025]],
    ['filters' => ['region' => 'north']],
    null,
    ['id' => 'r1', 'company_id' => 7, 'name' => 'R']
);
Harness::eq($compiled['chart'], 'pie', 'compile carries chart');
Harness::eq($compiled['filters'], ['year' => 2025, 'region' => 'north'], 'compile merges definition + param filters');
Harness::eq($compiled['company_id'], 7, 'compile carries company id');

// runner_missing path (definition loaded from a stub, no runner injected)
$stub = new StubbedReport(null, null, null, null);
$stub->row = ['name' => 'R', 'definition_json' => json_encode(['intent' => 'data_qa', 'chart' => 'bar', 'dataset' => 'sales', 'columns' => ['a']])];
$out = $stub->run('r1', ['filters' => ['year' => 2026]], null);
Harness::ok(($out['runner_missing'] ?? false) === true, 'run without a runner flags runner_missing');
Harness::eq($out['chart'] ?? null, 'bar', 'run returns the compiled request');

// with a runner wired, run() delegates
$called = false;
$runnerReport = new StubbedReport(null, function (array $d, array $p, $c) use (&$called): array {
    $called = true;
    return ['rows' => 42, 'chart' => $d['chart']];
}, null, null);
$runnerReport->row = ['name' => 'R', 'definition_json' => json_encode(['intent' => 'data_qa', 'chart' => 'line', 'dataset' => 'sales'])];
$rr = $runnerReport->run('r1', [], null);
Harness::ok($called && ($rr['rows'] ?? 0) === 42 && ($rr['chart'] ?? '') === 'line', 'run delegates to injected runner');

// ── SchedulesService (pure next-run + failure policy) ────────────────────────
section('SchedulesService');

Config::reset();
$sched = new SchedulesService(null);

// failure disabling rule
Harness::eq($sched->applyFailure(3, true, 5), ['failure_count' => 0, 'state' => 'active'], 'success resets failure count');
Harness::eq($sched->applyFailure(3, false, 5), ['failure_count' => 4, 'state' => 'active'], 'below threshold stays active');
Harness::eq($sched->applyFailure(4, false, 5), ['failure_count' => 5, 'state' => 'disabled'], 'at threshold disables');

// next-run computation via Cron
$anchor = new DateTimeImmutable('2026-01-05 09:00:00', $utc);
Harness::eq($sched->nextRunAt('0 9 * * 1-5', 'UTC', $anchor), '2026-01-06 09:00:00', 'nextRunAt skips to next weekday');
Harness::eq($sched->nextRunAt('bogus cron', 'UTC', $anchor), null, 'invalid cron -> null next run');

// nextRuns preview
$preview = $sched->nextRuns('0 * * * *', 3, 'UTC');
Harness::eq(count($preview), 3, 'nextRuns returns requested count');
Harness::eq($sched->nextRuns('not valid', 3, 'UTC'), [], 'nextRuns empty for invalid cron');

// create() refuses an invalid cron before touching the DB
Harness::throws(static function () use ($sched): void {
    $sched->create('Broken', 'nope nope');
}, 'create throws on invalid cron');

// a bad timezone falls back safely rather than throwing
Harness::ok(is_string($sched->nextRunAt('*/5 * * * *', 'Not/AZone', $anchor)), 'unknown timezone tolerated');

// ── result ───────────────────────────────────────────────────────────────────
Config::reset();
echo "\n----------------------------------------\n";
printf("%d passed, %d failed\n", Harness::$passed, Harness::$failed);
exit(Harness::$failed > 0 ? 1 : 0);
