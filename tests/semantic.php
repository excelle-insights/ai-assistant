<?php

/**
 * Semantic-layer checks: DomainPack derivation/validation and QueryCompiler
 * SQL generation. No database, no network, no LLM.
 *   php tests/semantic.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Dto\QueryPlan;
use ExcelleInsights\AiAssistant\Services\QueryCompiler;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\DomainPack;
use ExcelleInsights\AiAssistant\Support\SchemaPolicy;

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
    } catch (InvalidArgumentException $e) {
        check($name, $messageContains === '' || str_contains($e->getMessage(), $messageContains), $e->getMessage());
    }
}

/** A catalog shaped exactly like SchemaService::catalog() returns. */
$catalog = [
    'orders' => [
        'label' => 'Orders',
        'description' => 'Every order placed',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'description' => '', 'label' => 'Id', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'company_id', 'type' => 'int', 'description' => '', 'label' => 'Company', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'customer_id', 'type' => 'int', 'description' => '', 'label' => 'Customer', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'status', 'type' => 'varchar', 'description' => 'Order state', 'label' => 'Status', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'text', 'aliases' => ['state'], 'source' => 'user'],
            ['name' => 'total_amount', 'type' => 'decimal', 'description' => '', 'label' => 'Total', 'sensitivity' => 'internal', 'is_measure' => true, 'format' => 'money', 'aliases' => ['revenue'], 'source' => 'auto'],
            ['name' => 'cost_price', 'type' => 'decimal', 'description' => '', 'label' => 'Cost', 'sensitivity' => 'internal', 'is_measure' => true, 'format' => 'money', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'created_at', 'type' => 'datetime', 'description' => '', 'label' => 'Created', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'datetime', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'meta', 'type' => 'json', 'description' => '', 'label' => 'Meta', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'json', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'password', 'type' => 'varchar', 'description' => '', 'label' => 'Password', 'sensitivity' => 'secret', 'is_measure' => false, 'format' => 'text', 'aliases' => [], 'source' => 'auto'],
        ],
    ],
    'customers' => [
        'label' => 'Customers',
        'description' => '',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'description' => '', 'label' => 'Id', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'name', 'type' => 'varchar', 'description' => '', 'label' => 'Name', 'sensitivity' => 'pii', 'is_measure' => false, 'format' => 'text', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'company_id', 'type' => 'int', 'description' => '', 'label' => 'Company', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'total_spent', 'type' => 'decimal', 'description' => '', 'label' => 'Spent', 'sensitivity' => 'internal', 'is_measure' => true, 'format' => 'money', 'aliases' => [], 'source' => 'auto'],
        ],
    ],
    'currencies' => [
        'label' => 'Currencies',
        'description' => '',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'description' => '', 'label' => 'Id', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'number', 'aliases' => [], 'source' => 'auto'],
            ['name' => 'name', 'type' => 'varchar', 'description' => '', 'label' => 'Name', 'sensitivity' => 'internal', 'is_measure' => false, 'format' => 'text', 'aliases' => [], 'source' => 'auto'],
        ],
    ],
];

$tableColumns = [
    'orders' => ['id', 'company_id', 'customer_id', 'status', 'total_amount', 'cost_price', 'created_at', 'meta', 'password'],
    'customers' => ['id', 'name', 'company_id', 'total_spent'],
    'currencies' => ['id', 'name'],
];

Config::reset();
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.blocked_columns', ['cost_price']);
Config::set('schema_policy.max_rows', 200);
Config::set('schema_policy.default_limit', 20);
Config::set('tenant.column', 'company_id');
Config::set('timezone', 'UTC');

// ─── DomainPack: derived from the catalog ─────────────────────────────────────
$auto = new DomainPack([], $catalog);
check('pack: datasets derived per table', $auto->dataset('orders') !== null && $auto->dataset('customers') !== null, implode(',', array_keys($auto->datasets())));
$orders = $auto->dataset('orders');
same('pack: table recorded', 'orders', (string) $orders['table']);
check('pack: always has a count metric', $auto->metric('orders', 'records') !== null);
check('pack: measure becomes a money metric', ($auto->metric('orders', 'total_amount')['function'] ?? '') === 'sum', json_encode($auto->metric('orders', 'total_amount')));
check('pack: alias resolves', ($auto->metric('orders', 'revenue')['name'] ?? '') === 'total_amount');
check('pack: secret column never derived', $auto->dimension('orders', 'password') === null);
check('pack: json column never a dimension', $auto->dimension('orders', 'meta') === null);
check('pack: text column is a dimension', $auto->dimension('orders', 'status') !== null);
check('pack: alias resolves a dimension', ($auto->dimension('orders', 'state')['name'] ?? '') === 'status');
same('pack: date field derived from the first date column', 'created_at', (string) $auto->dataset('orders')['date_field']);
check('pack: entity derived', $auto->entity('customers') !== null && $auto->entity('customers')['title_column'] === 'name');
$check = new DomainPack([], []);
same('pack: empty catalog gives nothing', 0, count($check->datasets()));

$vocabulary = $auto->vocabulary();
check('vocab: lists dataset names', str_contains($vocabulary, 'orders:') && str_contains($vocabulary, 'customers'));
check('vocab: lists metrics and dimensions', str_contains($vocabulary, 'total_amount') && str_contains($vocabulary, 'status'));
check('vocab: never leaks a secret column', !str_contains($vocabulary, 'password'));
check('vocab: mentions the date field', str_contains($vocabulary, 'date[created_at]'));
check('vocab: budget respected', strlen($auto->vocabulary(120, 2)) <= 400, (string) strlen($auto->vocabulary(120, 2)));

// ─── DomainPack: host pack wins, and auto-derivation stands down ──────────────
$packs = [[
    'name' => 'sales',
    'datasets' => [[
        'name' => 'orders',
        'table' => 'orders',
        'label' => 'Sales',
        'date_field' => 'created_at',
        'metrics' => [
            ['name' => 'revenue', 'function' => 'sum', 'column' => 'total_amount', 'format' => 'money', 'aliases' => ['turnover']],
            ['name' => 'orders_count', 'function' => 'count_distinct', 'column' => 'id'],
        ],
        'dimensions' => [
            ['name' => 'status', 'column' => 'status'],
            ['name' => 'month', 'expr' => "DATE_FORMAT(orders.created_at, '%Y-%m')", 'label' => 'Month'],
            ['name' => 'customer_name', 'column' => 'customers.name', 'via' => 'customers'],
        ],
        'joins' => [['table' => 'customers', 'on' => ['orders.customer_id' => 'customers.id'], 'type' => 'inner']],
    ]],
    'entities' => [['name' => 'order', 'table' => 'orders', 'title_column' => 'id', 'fields' => ['id', 'status']]],
    'actions' => [['name' => 'create_lead', 'label' => 'Add a lead', 'handler' => 'crm', 'fields' => ['name' => ['required' => true], 'phone' => []]]],
]];
$pack = new DomainPack($packs, $catalog);
same('pack: authored dataset wins', 'sum', (string) $pack->metric('orders', 'revenue')['function']);
check('pack: authored expr kept', str_contains((string) $pack->dimension('orders', 'month')['expr'], 'DATE_FORMAT'));
check('pack: auto-derivation skips named tables', count($pack->datasets()) === 3 && $pack->dataset('customers') !== null && count(array_filter(array_map(static fn (array $d): string => (string) $d['table'], $pack->datasets()), static fn (string $t): bool => $t === 'orders')) === 1, implode(',', array_keys($pack->datasets())));
check('pack: action declared', ($pack->action('create_lead')['handler'] ?? '') === 'crm' && isset($pack->action('create_lead')['fields']['name']));
check('pack: entity from pack', $pack->entity('order') !== null && $pack->entity('order')['table'] === 'orders');
check('pack: join declared', ($pack->dataset('orders')['joins'][0]['table'] ?? '') === 'customers');
$loaded = DomainPack::load(null, ['packs' => $packs, 'catalog' => $catalog, 'auto_derive' => false]);
same('pack: load() honours auto_derive=false', 1, count($loaded->datasets()));
Config::set('semantic.packs', $packs);
$fromConfig = DomainPack::load(null, ['catalog' => $catalog, 'auto_derive' => false]);
check('pack: load() reads config packs', $fromConfig->metric('orders', 'turnover') !== null);
Config::set('semantic.packs', []);
same('pack: key normalises case and punctuation', DomainPack::key('Total Revenue!'), DomainPack::key('total_revenue'));

// ─── QueryCompiler ────────────────────────────────────────────────────────────
$compiler = new QueryCompiler($pack, new SchemaPolicy(), $tableColumns);
$scoped = ['tenant_id' => 7];

$compiled = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue']]), $scoped);
check('sql: aggregate wraps the sum', str_contains($compiled['sql'], 'ROUND(SUM(`orders`.`total_amount`), 2) AS `revenue`'), $compiled['sql']);
check('sql: no group by without dimensions', !str_contains($compiled['sql'], 'GROUP BY'));
check('sql: tenant filter injected', str_contains($compiled['sql'], '`orders`.`company_id` = :__tenant') && $compiled['bind'][':__tenant'] === 7, $compiled['sql']);
same('sql: default limit applied', 20, $compiled['limit']);
same('sql: dataset recorded', 'orders', $compiled['dataset']);
same('sql: single metric column', ['revenue'], $compiled['columns']);

$grouped = $compiler->compile(QueryPlan::fromArray([
    'dataset' => 'orders',
    'metrics' => ['revenue', 'orders_count'],
    'dimensions' => ['status'],
    'order_by' => [['field' => 'revenue', 'direction' => 'desc']],
]), $scoped);
check('sql: group by the dimension', str_contains($grouped['sql'], 'GROUP BY `orders`.`status`'), $grouped['sql']);
check('sql: dimension aliased', str_contains($grouped['sql'], '`orders`.`status` AS `status`'));
check('sql: count distinct', str_contains($grouped['sql'], 'COUNT(DISTINCT `orders`.`id`) AS `orders_count`'));
check('sql: orders by the metric alias', str_contains($grouped['sql'], 'ORDER BY `revenue` DESC'), $grouped['sql']);

$period = $compiler->compile(QueryPlan::fromArray([
    'dataset' => 'orders',
    'metrics' => ['revenue'],
    'dimensions' => ['status'],
    'date_range' => 'last month',
]), $scoped);
check('sql: date window bound, not interpolated', isset($period['bind'][':from'], $period['bind'][':to']) && str_contains($period['sql'], '`orders`.`created_at` >= :from'), json_encode($period['bind']));
same('sql: period label reported', 'last month', (string) ($period['applied_filters']['period'] ?? ''));

$derived = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['month']]), $scoped);
check('sql: pack expression used for a derived dimension', str_contains($derived['sql'], "(DATE_FORMAT(orders.created_at, '%Y-%m')) AS `month`"), $derived['sql']);

$joined = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['customer_name']]), $scoped);
check('sql: needed join added', str_contains($joined['sql'], 'INNER JOIN `customers` AS `customers` ON `orders`.`customer_id` = `customers`.`id`'), $joined['sql']);
check('sql: joined column qualified', str_contains($joined['sql'], '`customers`.`name` AS `customer_name`'));
$noJoin = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['status']]), $scoped);
check('sql: unused join omitted', !str_contains($noJoin['sql'], 'JOIN'), $noJoin['sql']);

$filtered = $compiler->compile(QueryPlan::fromArray([
    'dataset' => 'orders',
    'metrics' => ['revenue'],
    'filters' => [
        ['field' => 'status', 'operator' => 'in', 'value' => ['new', 'paid']],
        ['field' => 'status', 'operator' => 'not_in', 'value' => ['cancelled']],
        ['field' => 'status', 'operator' => 'like', 'value' => '40% off_x'],
        ['field' => 'status', 'operator' => 'between', 'value' => ['a', 'b']],
        ['field' => 'status', 'operator' => 'is_null', 'value' => null],
    ],
]), $scoped);
check('filters: in list bound', str_contains($filtered['sql'], 'IN (:f0_0, :f0_1)') && $filtered['bind'][':f0_0'] === 'new', $filtered['sql']);
check('filters: not in', str_contains($filtered['sql'], 'NOT IN (:f1_0)'));
check('filters: like escapes wildcards', $filtered['bind'][':f2'] === '%40\\% off\\_x%', (string) $filtered['bind'][':f2']);
check('filters: between bounds', str_contains($filtered['sql'], 'BETWEEN :f3_a AND :f3_b'));
check('filters: is null has no parameter', str_contains($filtered['sql'], '`orders`.`status` IS NULL') && count($filtered['bind']) === 7, (string) count($filtered['bind']));

$detail = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'entity' => 'order', 'dimensions' => ['status']]), $scoped);
check('detail: reads named fields without grouping', str_contains($detail['sql'], 'SELECT `orders`.`status` AS `status` FROM `orders`') && !str_contains($detail['sql'], 'GROUP BY'), $detail['sql']);
check('detail: newest first', str_contains($detail['sql'], 'ORDER BY `orders`.`created_at` DESC'), $detail['sql']);
check('detail: is not an aggregate', $detail['aggregate'] === false);

$limited = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'limit' => 99999]), $scoped);
same('limit: capped at the policy maximum', 200, $limited['limit']);
$asked = $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'limit' => 5]), $scoped);
same('limit: a small request survives', 5, $asked['limit']);

// Scope comes from the host, never from the plan.
$withScope = $compiler->compile(
    QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue']]),
    ['tenant_id' => 3, 'row_scope' => ['orders' => 'status = new'], 'hidden_columns' => ['orders' => ['customer_id']]]
);
check('scope: host fragment qualified', str_contains($withScope['sql'], '(`orders`.`status` = new)'), $withScope['sql']);
check('scope: applied filter surfaced', ($withScope['applied_filters']['row_scope'] ?? '') === 'restricted by your permissions');
throws('scope: hidden column refused', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['status']]), ['tenant_id' => 7, 'hidden_columns' => ['orders' => ['status']]]), 'not readable');
$autoCompiler = new QueryCompiler($auto, new SchemaPolicy(), $tableColumns);
throws('scope: policy-blocked column refused', fn () => $autoCompiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['cost_price']]), $scoped), 'not readable');
throws('scope: zero tenant blocked', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue']]), ['tenant_id' => 0]), 'No tenant');
throws('scope: hostile row fragment blocked', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue']]), ['row_scope' => ['orders' => 'id = 1; DROP TABLE orders']]), 'unusable');

$lookup = (new QueryCompiler($auto, new SchemaPolicy(), $tableColumns))->compile(QueryPlan::fromArray(['entity' => 'customers', 'metrics' => ['records']]), $scoped);
check('entity: resolves to its table', str_contains($lookup['sql'], 'FROM `customers`'), $lookup['sql']);

$unscoped = (new QueryCompiler(new DomainPack([['name' => 'meta', 'datasets' => [['name' => 'currencies', 'table' => 'currencies', 'metrics' => [['name' => 'records', 'function' => 'count', 'column' => '*']]]]]], $catalog), new SchemaPolicy(), $tableColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'currencies', 'metrics' => ['records']]), ['tenant_id' => 7]);
check('shared table: no tenant filter when it has no tenant column', !str_contains($unscoped['sql'], 'company_id'), $unscoped['sql']);

$noColumns = (new QueryCompiler($pack, new SchemaPolicy()))->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue']]), $scoped);
check('catalog-free compile still produces SQL', str_contains($noColumns['sql'], 'SUM(`orders`.`total_amount`)'), $noColumns['sql']);

// ─── Rejections: nothing the pack does not name reaches the database ──────────
throws('reject: unknown dataset', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'salaries', 'metrics' => ['total']]), $scoped), 'Unknown dataset');
throws('reject: unknown metric names the valid ones', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['profit']]), $scoped), 'Try: revenue');
throws('reject: unknown dimension', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'dimensions' => ['employee']]), $scoped), 'Unknown field');
throws('reject: injection through a metric name', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue`) ; DROP TABLE orders-- ']]), $scoped), 'Unknown metric');
throws('reject: injection through a filter field', fn () => $compiler->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'filters' => [['field' => 'status` = 1 OR 1=1 --', 'operator' => '=', 'value' => 'x']]]), $scoped), 'Unknown field');
throws('reject: unsupported operator refused', fn () => QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['revenue'], 'filters' => [['field' => 'status', 'operator' => 'regex', 'value' => 'x']]]), 'Unsupported filter operator');
// Config wins over the constructor fallback, so the allowlist is set there.
Config::set('schema_policy.mode', 'allowlist');
Config::set('schema_policy.allowed_tables', ['customers']);
throws('reject: blocked table', fn () => (new QueryCompiler($auto, new SchemaPolicy(), $tableColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['total_amount']]), $scoped), 'not readable');
Config::set('schema_policy.mode', 'denylist');
Config::set('schema_policy.allowed_tables', []);
check('reject: values are never in the SQL string', !str_contains($filtered['sql'], 'new') && !str_contains($filtered['sql'], 'cancelled'));

$badExpr = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders', 'date_field' => 'created_at',
        'metrics' => [['name' => 'evil', 'function' => 'expr', 'expr' => 'SLEEP(10)']],
        'dimensions' => [['name' => 'status', 'column' => 'status']],
    ]],
]], $catalog);
throws('reject: function not whitelisted', fn () => (new QueryCompiler($badExpr, new SchemaPolicy(), $tableColumns))->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['evil']]), $scoped), 'Unsafe expression');
$badExpr2 = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders',
        'metrics' => [['name' => 'evil', 'function' => 'expr', 'expr' => 'SELECT password FROM users']],
    ]],
]], $catalog);
throws('reject: keyword in an expression', fn () => (new QueryCompiler($badExpr2, new SchemaPolicy(), $tableColumns))->compile(QueryPlan::fromArray(['dataset' => 'orders', 'metrics' => ['evil']]), $scoped), 'unknown');

$decryptor = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders',
        'dimensions' => [['name' => 'customer', 'expr' => 'AES_DECRYPT(FROM_BASE64(orders.customer_id), @host_key)']],
    ]],
]], $catalog);
$decrypted = (new QueryCompiler($decryptor, new SchemaPolicy(), $tableColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'orders', 'dimensions' => ['customer']]), $scoped);
check('expression: decrypt helper and a session variable are allowed', str_contains($decrypted['sql'], 'AES_DECRYPT') && str_contains($decrypted['sql'], '@host_key'), $decrypted['sql']);

// The catalog hides blocked columns, but a host expression may still read them.
$slimColumns = ['orders' => ['id', 'status']];
$slimPack = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders',
        'dimensions' => [['name' => 'secret', 'expr' => "COALESCE(AES_DECRYPT(FROM_BASE64(orders.total_amount), @host_key), orders.total_amount)"]],
    ]],
]], $catalog, ['auto_derive' => false]);
$slim = (new QueryCompiler($slimPack, new SchemaPolicy(), $slimColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'orders', 'dimensions' => ['secret']]), $scoped);
check('expression: a qualified column the catalog hides is trusted', str_contains($slim['sql'], 'orders.total_amount'), $slim['sql']);

$unknownTable = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders',
        'dimensions' => [['name' => 'bad', 'expr' => 'nope.total_amount']],
    ]],
]], $catalog, ['auto_derive' => false]);
throws('expression: an unknown qualifier is still refused', fn () => (new QueryCompiler($unknownTable, new SchemaPolicy(), $slimColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'orders', 'dimensions' => ['bad']]), $scoped), 'unknown table');

$joinedPack = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'orders', 'table' => 'orders', 'date_field' => 'created_at',
        'dimensions' => [['name' => 'customer', 'column' => 'c.name']],
        'joins' => [['table' => 'customers', 'alias' => 'c', 'on' => ['orders.customer_id' => 'c.id']]],
    ]],
]], $catalog, ['auto_derive' => false]);
$joined = (new QueryCompiler($joinedPack, new SchemaPolicy(), $tableColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'orders', 'dimensions' => ['customer']]), $scoped);
check('join: an alias-prefixed column joins once', substr_count($joined['sql'], 'JOIN `customers`') === 1 && str_contains($joined['sql'], '`c`.`name`'), $joined['sql']);

// A pack writes the real table name; the compiler keys names without underscores.
$tableColumns['order_items'] = ['id', 'created_at', 'total_amount'];
$underscored = new DomainPack([[
    'name' => 'x',
    'datasets' => [[
        'name' => 'order_items', 'table' => 'order_items', 'date_field' => 'created_at',
        'metrics' => [['name' => 'total', 'function' => 'expr', 'expr' => 'SUM(order_items.total_amount)']],
        'dimensions' => [['name' => 'month', 'expr' => "DATE_FORMAT(order_items.created_at, '%Y-%m')"]],
    ]],
]], $catalog, ['auto_derive' => false]);
$grouped = (new QueryCompiler($underscored, new SchemaPolicy(), $tableColumns))
    ->compile(QueryPlan::fromArray(['dataset' => 'order_items', 'metrics' => ['total'], 'dimensions' => ['month']]), $scoped);
check('expression: an underscored table name resolves', str_contains($grouped['sql'], 'order_items.created_at') && str_contains($grouped['sql'], 'SUM(order_items.total_amount)'), $grouped['sql']);

Config::reset();
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
