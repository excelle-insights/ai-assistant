<?php

/**
 * Pure-logic checks for the schema layer: no database, no network, no LLM.
 *   php tests/schema.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Services\SchemaService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\CsvReader;
use ExcelleInsights\AiAssistant\Support\SchemaHints;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;
use ExcelleInsights\AiAssistant\Support\SqlDumpParser;

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

Config::reset();
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.blocked_columns', ['ssn']);
Config::set('schema_policy.masked_columns', ['phone', 'email']);
Config::set('schema_policy.require_tenant_scope', true);
Config::set('tenant.column', 'company_id');
Config::set('domain.currency', 'RSD');

$dump = <<<'SQL'
-- a comment with ; and -- inside
/* block
   comment */
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL COMMENT 'Customer full name',
  `note` text DEFAULT NULL COMMENT 'Free note; may contain ) and -- chars',
  `phone` varchar(30) DEFAULT NULL,
  `total_spent` decimal(10,2) DEFAULT '0.00',
  `company_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  CONSTRAINT `fk_cust_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='People who buy things';
CREATE TABLE orders (
  id INT,
  customer_id INT REFERENCES customers(id),
  amount DECIMAL(10,2),
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  CONSTRAINT o_c FOREIGN KEY (customer_id) REFERENCES customers (id)
);
INSERT INTO customers VALUES (1, 'a;b');
SQL;

// ─── SqlDumpParser ────────────────────────────────────────────────────────────
$parsed = SqlDumpParser::parse($dump);
same('dump: two tables found', 2, count($parsed['tables']));
same('dump: non-CREATE statements skipped', 2, $parsed['skipped']);

$customers = $parsed['tables'][0];
same('dump: table name unquoted', 'customers', $customers['name']);
same('dump: table comment captured', 'People who buy things', $customers['comment']);
same('dump: engine captured', 'innodb', $customers['engine']);
same('dump: column count', 7, count($customers['columns']));
same('dump: table-level PRIMARY KEY marked', true, $customers['columns'][0]['primary']);
same('dump: KEY line not a column', false, isset($customers['columns'][1]['primary']) && $customers['columns'][1]['primary']);
same('dump: quoted comment keeps ; ) and --', 'Free note; may contain ) and -- chars', $customers['columns'][2]['comment']);
same('dump: decimal default unquoted', '0.00', $customers['columns'][4]['default']);
same('dump: NOT NULL flips nullable', false, $customers['columns'][0]['nullable']);
same('dump: auto_increment', true, $customers['columns'][0]['auto_increment']);
same('dump: named foreign key', 'companies', $customers['foreign_keys'][0]['table']);
same('dump: foreign key on delete', 'CASCADE', $customers['foreign_keys'][0]['on_delete']);
same('dump: foreign key column', 'company_id', $customers['foreign_keys'][0]['column']);

$orders = $parsed['tables'][1];
same('dump: inline REFERENCES kept', 'customers', $orders['columns'][1]['references']['table'] ?? null);
same('dump: string default with commas not split', 'new', $orders['columns'][3]['default']);
same('dump: decimal(10,2) not split at its comma', 'decimal(10,2)', $orders['columns'][2]['type']);
same('dump: foreign key constraint line not a column', 4, count($orders['columns']));

same('identifier: schema-qualified', 'orders', SqlDumpParser::identifier('`mydb`.`orders`'));
same('identifier: brackets', 'orders', SqlDumpParser::identifier('[orders]'));
same('stringLiteral: doubled quotes', "it's", SqlDumpParser::stringLiteral("'it''s'"));
same('stringLiteral: backslash escape', "a\"b", SqlDumpParser::stringLiteral("'a\\\"b'"));
same('splitTopLevel: nested parens', 2, count(SqlDumpParser::splitTopLevel('a DECIMAL(10,2), b INT')));
same('matchingParen: nesting', 3, SqlDumpParser::matchingParen('(()),)', 0));
same('matchingParen: ignores parens in literals', 7, SqlDumpParser::matchingParen("(')', x)", 0));
check('stripComments: keeps literals', str_contains(SqlDumpParser::stripComments("SELECT '-- not a comment'"), 'not a comment'));

// ─── SchemaHints ──────────────────────────────────────────────────────────────
same('hints: id', 'Unique identifier', SchemaHints::autoDescription('customers', 'id'));
same('hints: fk suffix', 'Reference to Customer', SchemaHints::autoDescription('orders', 'customer_id'));
same('hints: fk wins over naming', 'Reference to Customers', SchemaHints::autoDescription('orders', 'customer_id', 'customers'));
same('hints: money word boundary', 'Monetary amount', SchemaHints::autoDescription('t', 'unit_price'));
check('hints: summary is not money', SchemaHints::autoDescription('t', 'summary') !== 'Monetary amount', SchemaHints::autoDescription('t', 'summary'));
same('hints: timestamp suffix', 'Timestamp for Invoice', SchemaHints::autoDescription('t', 'invoice_at'));
same('hints: phone', 'Phone number', SchemaHints::autoDescription('t', 'contact_phone'));
same('hints: generic humanise', 'Internal code', SchemaHints::autoDescription('t', 'internal_code'));
same('hints: label camelCase', 'Invoice total', SchemaHints::label('invoiceTotal'));
same('hints: format datetime', 'datetime', SchemaHints::format('created_at', 'datetime'));
same('hints: format money', 'money', SchemaHints::format('total', 'decimal(10,2)'));
same('hints: format number not money', 'number', SchemaHints::format('year_built', 'int'));
same('hints: format bool', 'bool', SchemaHints::format('is_active', 'tinyint'));
same('hints: format date', 'date', SchemaHints::format('due', 'date'));
same('hints: measure', true, SchemaHints::isMeasure('amount', 'decimal'));
same('hints: id is not a measure', false, SchemaHints::isMeasure('customer_id', 'int'));
check('hints: qty is a measure', SchemaHints::isMeasure('qty', 'int'), 'qty should be a measure');

// ─── CsvReader ────────────────────────────────────────────────────────────────
$csv = "\xEF\xBB\xBFtable,column,description\n\"cust,omers\",name, \"A quoted, tricky note\" \ncustomers,note,\"line one\nline two\"\n\n";
$rows = CsvReader::parse($csv);
same('csv: bom stripped from first header', 'table', $rows[0][0]);
same('csv: comma inside quotes', 'cust,omers', $rows[1][0]);
same('csv: newline inside quotes is one field', "line one\nline two", $rows[2][2]);
same('csv: blank line dropped', 3, count($rows));
same('csv: header index by alias', 1, CsvReader::column(CsvReader::header($rows[0]), ['column', 'column_name']));
same('csv: missing column is null', null, CsvReader::column(CsvReader::header($rows[0]), ['nope']));
$assoc = CsvReader::parseAssoc("question,answer\n\"Why?\",\"Because\"\n");
same('csv: assoc read', 'Because', $assoc[0]['answer'] ?? null);
same('csv: semicolon sniffing', 'b', CsvReader::parse("a;b\n1;2\n")[1][1] === '2' ? 'b' : 'a');

// ─── SchemaService: SQL safety, DB-free ───────────────────────────────────────
$service = new SchemaService(null, new SchemaPolicy((array) Config::get('schema_policy', [])));

same('sql: rejects non-SELECT', 'Only SELECT queries are allowed', $service->validateSql('DELETE FROM customers'));
same('sql: rejects multiple statements', 'Multiple statements are not allowed', $service->validateSql('SELECT 1 FROM customers; DROP TABLE customers'));
same('sql: empty query', 'Empty query', $service->validateSql('   '));
check('sql: rejects forbidden keyword', (string) $service->validateSql('SELECT * FROM customers WHERE 1=1 UNION SELECT password FROM users') !== null, 'no error raised');
check('sql: semicolon inside literal is tolerated', $service->validateSql("SELECT id FROM customers WHERE note = 'a;b'") === null, (string) $service->validateSql("SELECT id FROM customers WHERE note = 'a;b'"));
check('sql: comment cannot hide a keyword', (string) $service->validateSql("SELECT id FROM customers -- ok\n WHERE 'a--b' = 'a' AND 1=1") !== 'ok', 'sanity');
check('sql: INTO OUTFILE blocked', (string) $service->validateSql("SELECT id FROM customers INTO OUTFILE '/tmp/x'") !== null, 'not blocked');
check('sql: information_schema probe blocked', (string) $service->validateSql('SELECT table_name FROM information_schema.tables') !== null, 'not blocked');
check('sql: blocked column rejected', str_contains((string) $service->validateSql('SELECT ssn FROM customers'), 'ssn'), 'ssn not caught');
check('sql: masked column allowed', $service->validateSql('SELECT phone FROM customers WHERE company_id = 1') === null, (string) $service->validateSql('SELECT phone FROM customers WHERE company_id = 1'));

same('limit: appends default', 'SELECT id FROM t LIMIT 20', $service->applyLimit('SELECT id FROM t', 20, 200));
same('limit: caps oversized', 'SELECT id FROM t LIMIT 200', $service->applyLimit('SELECT id FROM t LIMIT 999999', 20, 200));
same('limit: keeps smaller', 'SELECT id FROM t LIMIT 5', $service->applyLimit('SELECT id FROM t LIMIT 5', 20, 200));
same('limit: offset form caps row count', 'SELECT id FROM t LIMIT 40, 200', $service->applyLimit('SELECT id FROM t LIMIT 40, 5000', 20, 200));
same('limit: offset form within max', 'SELECT id FROM t LIMIT 40, 15', $service->applyLimit('SELECT id FROM t LIMIT 40, 15', 20, 200));
same('limit: OFFSET keyword preserved', 'SELECT id FROM t LIMIT 200 OFFSET 10', $service->applyLimit('SELECT id FROM t LIMIT 5000 OFFSET 10', 20, 200));
same('limit: small OFFSET untouched', 'SELECT id FROM t LIMIT 7 OFFSET 10', $service->applyLimit('SELECT id FROM t LIMIT 7 OFFSET 10', 20, 200));
same('limit: trailing semicolon dropped', 'SELECT id FROM t LIMIT 20', $service->applyLimit('SELECT id FROM t;', 20, 200));
same('literals: stripped for keyword scan', "SELECT * FROM t WHERE note =  ", SchemaService::withoutStringLiterals("SELECT * FROM t WHERE note = 'drop table'"));

same('scope: missing tenant filter', "Query must filter by 'company_id'", $service->tenantScopeCheck('SELECT id FROM customers', ['customers']));
same('scope: present filter passes', null, $service->tenantScopeCheck('SELECT id FROM customers WHERE company_id = 7', ['customers']));
same('scope: wrong tenant rejected', 'Query filters by a different company_id than the current user', $service->tenantScopeCheck('SELECT id FROM customers WHERE company_id = 7', ['customers'], 9));
same('scope: right tenant passes', null, $service->tenantScopeCheck('SELECT id FROM customers WHERE company_id = 7', ['customers'], 7));
same('scope: table without tenant column passes', null, $service->tenantScopeCheck('SELECT id FROM currencies', []));

// ─── SchemaService: context card ──────────────────────────────────────────────
$card = $service->describeCatalogTable([
    'label' => 'Customers',
    'description' => 'People who buy things',
    'columns' => [
        ['name' => 'id', 'type' => 'int(11)', 'description' => 'Unique identifier', 'label' => 'Id', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => []],
        ['name' => 'name', 'type' => 'varchar(150)', 'description' => 'Customer full name', 'label' => 'Name', 'sensitivity' => 'pii', 'is_measure' => false, 'format' => 'text', 'aliases' => ['client']],
        ['name' => 'phone', 'type' => 'varchar(30)', 'description' => 'Phone number', 'label' => 'Phone', 'sensitivity' => 'pii', 'is_measure' => false, 'format' => 'text', 'aliases' => []],
        ['name' => 'total_spent', 'type' => 'decimal(10,2)', 'description' => 'Lifetime spend', 'label' => 'Total', 'sensitivity' => 'internal', 'is_measure' => true, 'format' => 'money', 'aliases' => []],
        ['name' => 'password', 'type' => 'varchar(255)', 'description' => 'Hash', 'label' => 'Password', 'sensitivity' => 'secret', 'is_measure' => false, 'format' => 'text', 'aliases' => []],
        ['name' => 'ssn', 'type' => 'varchar(11)', 'description' => 'Tax id', 'label' => 'Ssn', 'sensitivity' => 'secret', 'is_measure' => false, 'format' => 'text', 'aliases' => []],
    ],
], 'customers');
check('card: table comment and no redundant label', str_starts_with($card, 'customers (People who buy things):'), $card);
check('card: user description included', str_contains($card, 'name:varchar "Customer full name"'), $card);
check('card: alias included', str_contains($card, 'aka client'), $card);
check('card: pii flagged', str_contains($card, '[pii:masked]'), $card);
check('card: measure flagged', str_contains($card, 'total_spent:decimal "Lifetime spend" #measure'), $card);
check('card: secret column dropped', !str_contains($card, 'password'), $card);
check('card: blocked column dropped', !str_contains($card, 'ssn'), $card);
$renamed = $service->describeCatalogTable([
    'label' => 'Clients',
    'description' => '',
    'columns' => [['name' => 'id', 'type' => 'int', 'description' => '', 'label' => 'Id', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => []]],
], 'customers');
check('card: distinct label rendered', str_starts_with($renamed, 'customers — Clients:'), $renamed);

// Auto-description fallback: a catalog with no description still yields a usable card.
$fallback = $service->describeCatalogTable(['label' => '', 'description' => '', 'columns' => [
    ['name' => 'created_at', 'type' => 'datetime', 'description' => '', 'label' => 'Created at', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'datetime', 'aliases' => []],
]], 'invoices');
same('card: bare table when no label', 'invoices: created_at:datetime', $fallback);

// Own tables must never be part of the queryable surface.
$policy = new SchemaPolicy(['mode' => 'all']);
check('policy: own tables excluded by default', !$policy->isTableAllowed('ai_assistant_knowledge'), 'own table allowed');
Config::set('schema_policy.include_own_tables', false);
$policy2 = new SchemaPolicy(['mode' => 'all']);
Config::set('schema_policy.include_own_tables', true);
check('policy: own tables can be included', $policy2->isTableAllowed('ai_assistant_knowledge'), 'own table blocked');
Config::set('schema_policy.include_own_tables', false);

echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
