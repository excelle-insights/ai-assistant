<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\TextScore;
use Throwable;

/**
 * The business rules of a CRM: owned, sourced and verified.
 *
 * The knowledge base is a pile of documents. This turns the part of it that
 * states a rule into something a person is accountable for: an owner, a source,
 * a verification state and the date it was last checked.
 *
 * Two states matter. A *draft* rule was written but nobody confirmed it, so it
 * only speaks when nothing else answers. A *verified* rule was checked against
 * the data, so it leads the prompt and the answer.
 *
 * The rules live in the knowledge table: a rule is a fact like any other, and
 * the difference between a rule and a document is its metadata, not a second
 * store to keep in step.
 */
final class RuleService
{
    /** Written, not confirmed. It waits for a person. */
    public const VERIFICATION_DRAFT = 'draft';

    /** Confirmed against the data by somebody who owns it. */
    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATIONS = [self::VERIFICATION_DRAFT, self::VERIFICATION_VERIFIED];

    public const CATEGORY_MODULE = 'module';
    public const CATEGORY_RULE = 'rule';
    public const CATEGORY_GLOSSARY = 'glossary';
    public const CATEGORY_CALCULATION = 'calculation';
    public const CATEGORY_PERMISSION = 'permission';
    public const CATEGORY_POLICY = 'policy';

    public const CATEGORIES = [
        self::CATEGORY_MODULE,
        self::CATEGORY_RULE,
        self::CATEGORY_GLOSSARY,
        self::CATEGORY_CALCULATION,
        self::CATEGORY_PERMISSION,
        self::CATEGORY_POLICY,
    ];

    /** Marks the rows a modules sheet owns, so an import updates its own rows and no others. */
    public const SHEET = 'modules';

    /** The columns of the modules and operations sheet, in order. */
    public const SHEET_COLUMNS = [
        'module', 'purpose', 'operations', 'tables', 'key_fields',
        'business_rules', 'permissions', 'owner', 'source_file', 'status',
    ];

    private ?KnowledgeService $knowledge;

    /** @var array<string,mixed> */
    private array $options;

    /** @param array<string,mixed> $options match_threshold, max_prompt */
    public function __construct(?KnowledgeService $knowledge = null, array $options = [])
    {
        $this->knowledge = $knowledge;
        $this->options = $options;
    }

    // ─── reading ──────────────────────────────────────────────────────────────

    /**
     * The rules of this CRM.
     *
     * @param array<string,mixed> $filter verification, module, category, limit
     * @return array<int,array<string,mixed>>
     */
    public function list(int $companyId, array $filter = []): array
    {
        if ($this->knowledge === null) {
            return [];
        }

        $category = trim((string) ($filter['category'] ?? ''));
        $categories = $category !== '' && in_array($category, self::CATEGORIES, true)
            ? [$category]
            : self::CATEGORIES;

        try {
            $rows = $this->knowledge->listByCategory($companyId, $categories, max(1, (int) ($filter['limit'] ?? 500)));
        } catch (Throwable) {
            return [];
        }

        $verification = trim((string) ($filter['verification'] ?? ''));
        $module = trim((string) ($filter['module'] ?? ''));

        $out = [];
        foreach ($rows as $row) {
            $rule = $this->ruleFrom($row);
            if ($rule === null) {
                continue;
            }
            if ($verification !== '' && $rule['verification'] !== $verification) {
                continue;
            }
            if ($module !== '' && strcasecmp($rule['module'], $module) !== 0) {
                continue;
            }
            $out[] = $rule;
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $companyId): ?array
    {
        if ($this->knowledge === null) {
            return null;
        }
        try {
            $row = $this->knowledge->find($id, $companyId);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : $this->ruleFrom($row);
    }

    /**
     * The rules that match a question.
     *
     * Verified rules come first. A draft rule is only offered when no verified
     * rule matched, because a rule nobody checked must never outrank one that
     * was checked.
     *
     * @return array<int,array<string,mixed>>
     */
    public function match(int $companyId, string $question, int $k = 3): array
    {
        $question = trim($question);
        if ($question === '' || $this->knowledge === null) {
            return [];
        }
        $terms = TextScore::tokenize($question);
        if ($terms === []) {
            return [];
        }
        $threshold = (float) ($this->options['match_threshold'] ?? Config::float('rules.match_threshold', 0.15));

        $scored = [];
        foreach ($this->list($companyId, ['limit' => 500]) as $rule) {
            $score = TextScore::keywordScore($terms, [
                'title' => (string) $rule['title'],
                'content' => (string) $rule['content'],
                'module' => (string) $rule['module'],
                'rule' => (string) $rule['rule'],
            ], $question);
            if ($score < $threshold) {
                continue;
            }
            $rule['score'] = $score;
            $scored[] = $rule;
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = (float) $b['score'] <=> (float) $a['score'];
            if ($diff !== 0) {
                return $diff;
            }
            $verified = (int) ($b['verification'] === self::VERIFICATION_VERIFIED)
                - (int) ($a['verification'] === self::VERIFICATION_VERIFIED);

            return $verified !== 0 ? $verified : strcmp((string) $a['title'], (string) $b['title']);
        });

        $verified = array_values(array_filter(
            $scored,
            static fn (array $rule): bool => $rule['verification'] === self::VERIFICATION_VERIFIED,
        ));

        return array_slice($verified !== [] ? $verified : $scored, 0, max(1, $k));
    }

    // ─── writing ──────────────────────────────────────────────────────────────

    /**
     * Store a rule.
     *
     * With an id it updates that row; without one it updates the rule of the
     * same module and operation, so saving twice never creates a second copy.
     *
     * @param array<string,mixed> $data id, title, module, operation, category,
     *                                   purpose, rule, operations, tables,
     *                                   key_fields, permissions, owner,
     *                                   source_file, example_question,
     *                                   verification
     * @return array<string,mixed> the stored rule
     */
    public function save(int $companyId, array $data, ?string $updatedBy = null): array
    {
        if ($this->knowledge === null) {
            throw new \RuntimeException('The rule base needs a database.');
        }

        $module = trim((string) ($data['module'] ?? ''));
        $operation = trim((string) ($data['operation'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $module !== '' && $operation !== '' ? $module . ' — ' . $operation : ($module ?: $operation);
        }
        if ($title === '') {
            throw new \InvalidArgumentException('A rule needs a name — a module or an operation of one.');
        }

        $category = strtolower(trim((string) ($data['category'] ?? '')));
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = $operation !== '' ? self::CATEGORY_RULE : self::CATEGORY_MODULE;
        }

        $existing = null;
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $existing = $this->find($id, $companyId);
        }
        if ($existing === null) {
            $existing = $this->findByKey($companyId, $this->scopeKey($module, $operation), $title, $category);
        }
        if ($existing !== null) {
            $id = (int) $existing['id'];
        }

        $verification = strtolower(trim((string) ($data['verification'] ?? '')));
        if (!in_array($verification, self::VERIFICATIONS, true)) {
            // Nobody said anything about the state, so a rule somebody checked
            // stays checked.
            $verification = $existing !== null
                && (string) ($existing['verification'] ?? '') === self::VERIFICATION_VERIFIED
                ? self::VERIFICATION_VERIFIED
                : self::VERIFICATION_DRAFT;
        }

        $meta = $this->metaFrom($data, $module, $operation, $verification, $existing);
        $content = $this->contentFor($title, $meta);
        $scopeKey = $this->scopeKey($module, $operation);

        $outcome = $this->knowledge->ingestRow(
            $companyId,
            $title,
            $content,
            self::SOURCE_MANUAL,
            $category,
            [
                'scope' => 'global',
                'scope_key' => $scopeKey,
                'updated_by' => $updatedBy,
                'metadata' => $meta,
            ],
        );
        $id = (int) ($outcome['id'] ?? $id);

        // ingestRow keeps the metadata it already had, so the rule's own fields
        // are written again afterwards — a verification must overwrite the
        // draft it replaced.
        $this->knowledge->update($id, $companyId, ['metadata' => $meta]);

        $stored = $this->find($id, $companyId);

        return $stored ?? ['id' => $id, 'title' => $title];
    }

    /**
     * Confirm a rule — or send it back to draft.
     *
     * Verification is a decision, so it records who made it and when. A check
     * that came back empty is kept with the rule, so a reviewer sees the
     * contradiction instead of a green tick.
     *
     * @param array<string,mixed> $data verification, owner, example_question, check
     * @return array<string,mixed>|null the rule as it now stands
     */
    public function verify(int $id, int $companyId, array $data = []): ?array
    {
        if ($this->knowledge === null) {
            return null;
        }
        $existing = $this->find($id, $companyId);
        if ($existing === null) {
            return null;
        }

        $verification = strtolower(trim((string) ($data['verification'] ?? self::VERIFICATION_VERIFIED)));
        if (!in_array($verification, self::VERIFICATIONS, true)) {
            $verification = self::VERIFICATION_VERIFIED;
        }

        $meta = $this->metaFrom([
            'purpose' => (string) $existing['purpose'],
            'rule' => (string) $existing['rule'],
            'operations' => (array) $existing['operations'],
            'tables' => (array) $existing['tables'],
            'key_fields' => (array) $existing['key_fields'],
            'permissions' => (string) $existing['permissions'],
            'source_file' => (string) $existing['source_file'],
            'owner' => trim((string) ($data['owner'] ?? '')) !== ''
                ? (string) $data['owner']
                : (string) $existing['owner'],
            'example_question' => trim((string) ($data['example_question'] ?? '')) !== ''
                ? (string) $data['example_question']
                : (string) $existing['example_question'],
        ], (string) $existing['module'], (string) $existing['operation'], $verification, $existing);
        $meta['last_verified'] = $verification === self::VERIFICATION_VERIFIED ? date('Y-m-d') : '';
        if (isset($data['check']) && is_array($data['check'])) {
            $meta['check'] = $data['check'];
        }

        $this->knowledge->update($id, $companyId, ['metadata' => $meta]);

        return $this->find($id, $companyId);
    }

    /** Forget a rule. */
    public function delete(int $id, int $companyId): bool
    {
        if ($this->knowledge === null) {
            return false;
        }
        try {
            return $this->knowledge->delete($id, $companyId);
        } catch (Throwable) {
            return false;
        }
    }

    // ─── the modules and operations sheet ─────────────────────────────────────

    /**
     * A starter sheet for a host's admin screen.
     *
     * The sheet is optional: a CRM whose modules are clear from the navigation
     * map needs verification, not authorship.
     */
    public function sheetTemplate(): string
    {
        $lines = [
            implode(',', self::SHEET_COLUMNS),
            '"Payments","Records every payment received against an invoice.","create, approve, export",'
                . '"payments, invoices","id, amount, paid_at",'
                . '"An invoice is settled once the payments against it cover its total.",'
                . '"Finance staff approve payments; everyone else may only view them.","Jane Doe",'
                . '"modules/payments.php","draft"',
            '"Leads","Every enquiry that has not yet become a booking.","create, qualify, convert",'
                . '"leads","id, name, source",'
                . '"A lead is won when it is converted to a booking; the source stays as it was first recorded.",'
                . '"Sales staff own their own leads.","Jane Doe","modules/leads.php","draft"',
        ];

        return implode("\n", $lines);
    }

    /**
     * Import the modules and operations sheet.
     *
     * One row is one rule. A row that names a single operation and no purpose
     * describes that operation; every other row describes the module itself.
     *
     * The import owns the rules it created: re-importing updates them and drops
     * the ones the sheet no longer has, and leaves every other fact alone.
     *
     * @return array{inserted:int,updated:int,unchanged:int,removed:int,skipped:int,errors:list<string>,warnings:list<string>}
     */
    public function importSheet(int $companyId, string $csv, ?string $updatedBy = null): array
    {
        $result = [
            'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0,
            'skipped' => 0, 'errors' => [], 'warnings' => [],
        ];
        if (trim($csv) === '') {
            $result['errors'][] = 'CSV is empty';
            return $result;
        }

        $rows = array_map(
            static fn ($r): array => str_getcsv((string) $r, ',', '"', '\\'),
            preg_split('/\r\n|\r|\n/', trim($csv)) ?: [],
        );
        if ($rows === []) {
            $result['errors'][] = 'CSV is empty';
            return $result;
        }

        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $rows[0]);
        $index = [];
        foreach (self::SHEET_COLUMNS as $column) {
            $index[$column] = array_search($column, $header, true);
        }
        if ($index['module'] === false) {
            $result['errors'][] = 'The sheet needs a "module" column (' . implode(', ', self::SHEET_COLUMNS) . ')';
            return $result;
        }

        $written = [];
        $kinds = [];
        foreach (array_slice($rows, 1) as $i => $row) {
            $module = trim((string) ($row[$index['module']] ?? ''));
            if ($module === '') {
                $result['skipped']++;
                continue;
            }
            $cell = static fn (string $column): string => $index[$column] === false
                ? ''
                : trim((string) ($row[$index[$column]] ?? ''));

            $purpose = $cell('purpose');
            $operations = $this->splitList($cell('operations'));
            $operation = count($operations) === 1 && $purpose === '' ? $operations[0] : '';

            $kind = $operation !== '' ? 'operation' : 'module';
            $kinds[$kind] = true;

            $data = [
                'title' => $operation !== '' ? $module . ' — ' . $operation : $module,
                'module' => $module,
                'operation' => $operation,
                'category' => $operation !== '' ? self::CATEGORY_RULE : self::CATEGORY_MODULE,
                'purpose' => $purpose,
                'rule' => $cell('business_rules'),
                'operations' => $operations,
                'tables' => $this->splitList($cell('tables')),
                'key_fields' => $this->splitList($cell('key_fields')),
                'permissions' => $cell('permissions'),
                'owner' => $cell('owner'),
                'source_file' => $cell('source_file'),
                'verification' => $this->verificationOf($cell('status')),
                'sheet' => self::SHEET,
            ];

            $key = $this->scopeKey($module, $operation);
            $written[$key] = true;

            try {
                $before = $this->findByKey($companyId, $key, (string) $data['title'], (string) $data['category']);
                if ($before !== null && (string) ($before['verification'] ?? '') === self::VERIFICATION_VERIFIED) {
                    // The sheet says what a rule is; the person who checked it
                    // says whether it is true. A re-import does not uncheck it.
                    $data['verification'] = self::VERIFICATION_VERIFIED;
                }
                $stored = $this->save($companyId, $data, $updatedBy);
                if ($before === null) {
                    $result['inserted']++;
                } elseif ($this->changed($before, $stored)) {
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
            }
        }

        if (count($kinds) > 1) {
            $result['warnings'][] = 'The sheet mixes module rows and operation rows. One kind per file keeps it sortable.';
        }

        // Rules this sheet owns and no longer has.
        foreach ($this->list($companyId, ['limit' => 500]) as $rule) {
            if ((string) ($rule['sheet'] ?? '') !== self::SHEET) {
                continue;
            }
            $key = $this->scopeKey((string) $rule['module'], (string) $rule['operation']);
            if (isset($written[$key])) {
                continue;
            }
            if ($this->delete((int) $rule['id'], $companyId)) {
                $result['removed']++;
            }
        }

        return $result;
    }

    // ─── the prompt ───────────────────────────────────────────────────────────

    /**
     * A short "this is how we do it here" section for the router prompt.
     */
    public function promptSection(int $companyId, string $question, int $k = 3): string
    {
        $matches = $this->match($companyId, $question, $k);
        if ($matches === []) {
            return '';
        }

        $verified = array_values(array_filter(
            $matches,
            static fn (array $rule): bool => $rule['verification'] === self::VERIFICATION_VERIFIED,
        ));

        $lines = [];
        if ($verified !== []) {
            $lines[] = 'Business rules this CRM follows (read them; the vocabulary still decides what you may query):';
            foreach ($verified as $rule) {
                $lines[] = $this->promptLine($rule);
            }
            return implode("\n", $lines);
        }

        $lines[] = 'Business rules nobody has verified yet (use only when nothing else answers):';
        foreach ($matches as $rule) {
            $lines[] = $this->promptLine($rule);
        }

        return implode("\n", $lines);
    }

    // ─── internals ────────────────────────────────────────────────────────────

    /** Where a hand-written rule came from. */
    private const SOURCE_MANUAL = 'manual';

    /**
     * The knowledge row as a rule, or null when the row is just a document.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function ruleFrom(array $row): ?array
    {
        $meta = (array) ($row['metadata'] ?? []);
        if (!isset($meta['verification'])) {
            return null;
        }
        $check = (array) ($meta['check'] ?? []);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'module' => (string) ($meta['module'] ?? ''),
            'operation' => (string) ($meta['operation'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'purpose' => (string) ($meta['purpose'] ?? ''),
            'rule' => (string) ($meta['rule'] ?? ''),
            'operations' => array_values(array_map('strval', (array) ($meta['operations'] ?? []))),
            'tables' => array_values(array_map('strval', (array) ($meta['tables'] ?? []))),
            'key_fields' => array_values(array_map('strval', (array) ($meta['key_fields'] ?? []))),
            'permissions' => (string) ($meta['permissions'] ?? ''),
            'owner' => (string) ($meta['owner'] ?? ''),
            'source_file' => (string) ($meta['source_file'] ?? ''),
            'example_question' => (string) ($meta['example_question'] ?? ''),
            'verification' => (string) ($meta['verification'] ?? self::VERIFICATION_DRAFT),
            'last_verified' => (string) ($meta['last_verified'] ?? ''),
            'check' => $check,
            'needs_review' => $check !== [] && (string) ($check['status'] ?? '') !== 'ok',
            'sheet' => (string) ($meta['sheet'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'updated_by' => (string) ($row['updated_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * The metadata a rule carries.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $existing the rule as it stands
     * @return array<string,mixed>
     */
    private function metaFrom(
        array $data,
        string $module,
        string $operation,
        string $verification,
        ?array $existing = null,
    ): array {
        $meta = [
            'module' => $module,
            'operation' => $operation,
            'purpose' => trim((string) ($data['purpose'] ?? '')),
            'rule' => trim((string) ($data['rule'] ?? '')),
            'operations' => array_values(array_map('strval', (array) ($data['operations'] ?? []))),
            'tables' => array_values(array_map('strval', (array) ($data['tables'] ?? []))),
            'key_fields' => array_values(array_map('strval', (array) ($data['key_fields'] ?? []))),
            'permissions' => trim((string) ($data['permissions'] ?? '')),
            'owner' => trim((string) ($data['owner'] ?? '')),
            'source_file' => trim((string) ($data['source_file'] ?? '')),
            'example_question' => trim((string) ($data['example_question'] ?? '')),
            'verification' => $verification,
            'last_verified' => '',
        ];

        if ($existing !== null && $existing['owner'] !== '' && $meta['owner'] === '') {
            $meta['owner'] = (string) $existing['owner'];
        }
        if ($existing !== null && $existing['last_verified'] !== '' && $meta['last_verified'] === '') {
            $meta['last_verified'] = (string) $existing['last_verified'];
        }
        if ($existing !== null && $existing['example_question'] !== '' && $meta['example_question'] === '') {
            $meta['example_question'] = (string) $existing['example_question'];
        }
        if ($existing !== null && isset($existing['check']) && $existing['check'] !== [] && !isset($data['check'])) {
            $meta['check'] = (array) $existing['check'];
        }
        if (isset($data['sheet'])) {
            $meta['sheet'] = (string) $data['sheet'];
        } elseif ($existing !== null && $existing['sheet'] !== '') {
            $meta['sheet'] = (string) $existing['sheet'];
        }

        return $meta;
    }

    /**
     * The text the assistant reads: plain sentences, never SQL.
     *
     * @param array<string,mixed> $meta
     */
    private function contentFor(string $title, array $meta): string
    {
        $lines = [];
        $module = (string) ($meta['module'] ?? '');
        $operation = (string) ($meta['operation'] ?? '');
        $head = $module !== '' && $operation !== '' ? $module . ' — ' . $operation : ($module !== '' ? $module : $title);
        $lines[] = 'Module: ' . $head;
        if ((string) ($meta['purpose'] ?? '') !== '') {
            $lines[] = 'Purpose: ' . $meta['purpose'];
        }
        $operations = array_values(array_filter((array) ($meta['operations'] ?? []), 'strlen'));
        if ($operations !== []) {
            $lines[] = 'Operations: ' . implode(', ', $operations);
        }
        $tables = array_values(array_filter((array) ($meta['tables'] ?? []), 'strlen'));
        if ($tables !== []) {
            $lines[] = 'Tables it owns: ' . implode(', ', $tables);
        }
        $fields = array_values(array_filter((array) ($meta['key_fields'] ?? []), 'strlen'));
        if ($fields !== []) {
            $lines[] = 'Fields people read: ' . implode(', ', $fields);
        }
        if ((string) ($meta['rule'] ?? '') !== '') {
            $lines[] = 'Rule: ' . $meta['rule'];
        }
        if ((string) ($meta['permissions'] ?? '') !== '') {
            $lines[] = 'Permissions: ' . $meta['permissions'];
        }

        return implode("\n", $lines);
    }

    /**
     * The prompt line for one rule.
     *
     * @param array<string,mixed> $rule
     */
    private function promptLine(array $rule): string
    {
        $head = '- ' . (string) $rule['title'];
        $owner = trim((string) ($rule['owner'] ?? ''));
        if ($owner !== '') {
            $head .= ' (owner: ' . $owner;
            $last = trim((string) ($rule['last_verified'] ?? ''));
            $head .= $last !== '' ? ', verified ' . $last . ')' : ')';
        }
        $lines = [$head];
        if ((string) ($rule['rule'] ?? '') !== '') {
            $lines[] = '  ' . mb_substr(trim((string) $rule['rule']), 0, 240);
        } elseif ((string) ($rule['purpose'] ?? '') !== '') {
            $lines[] = '  ' . mb_substr(trim((string) $rule['purpose']), 0, 240);
        }

        return implode("\n", $lines);
    }

    /**
     * The key a rule is stored under: its module, and the operation when it
     * describes one.
     */
    private function scopeKey(string $module, string $operation): string
    {
        $slug = static fn (string $text): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? ''));

        $key = $slug($module);
        if ($operation !== '') {
            $key = ($key !== '' ? $key : 'rule') . '::' . $slug($operation);
        }

        return mb_substr($key !== '' ? $key : 'rule', 0, 180);
    }

    /**
     * The rule stored under this key, if there is one.
     *
     * @return array<string,mixed>|null
     */
    private function findByKey(int $companyId, string $scopeKey, string $title, string $category): ?array
    {
        if ($this->knowledge === null) {
            return null;
        }
        try {
            $rows = $this->knowledge->listByCategory($companyId, [$category], 500);
        } catch (Throwable) {
            return null;
        }
        foreach ($rows as $row) {
            if ((string) ($row['scope_key'] ?? '') === $scopeKey && (string) ($row['title'] ?? '') === $title) {
                $rule = $this->ruleFrom($row);
                if ($rule !== null) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /**
     * Did the stored rule change?
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function changed(array $before, array $after): bool
    {
        foreach (['title', 'module', 'operation', 'purpose', 'rule', 'permissions', 'owner', 'source_file'] as $field) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                return true;
            }
        }
        foreach (['operations', 'tables', 'key_fields'] as $field) {
            if ((array) ($before[$field] ?? []) !== (array) ($after[$field] ?? [])) {
                return true;
            }
        }

        return (string) ($before['content'] ?? '') !== (string) ($after['content'] ?? '');
    }

    /** A cell of the sheet: a list, however the author wrote it. */
    private function splitList(string $cell): array
    {
        $parts = preg_split('/[;|\n]+|,/', $cell) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    /** The verification the sheet asks for; anything unknown means a draft. */
    private function verificationOf(string $status): string
    {
        return strtolower(trim($status)) === self::VERIFICATION_VERIFIED
            ? self::VERIFICATION_VERIFIED
            : self::VERIFICATION_DRAFT;
    }
}
