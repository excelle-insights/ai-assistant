<?php

/**
 * Confirmed-write and delivery-queue checks: a proposal cannot become a write
 * without its token, a token cannot be used twice, a retried execute returns the
 * first result, and an outbox row backs off instead of spinning. SQLite stands in
 * for the host database.
 *   php tests/actions.php
 */

declare(strict_types=1);

use ExcelleInsights\AiAssistant\Contracts\ActionHandlerInterface;
use ExcelleInsights\AiAssistant\Contracts\NotificationInterface;
use ExcelleInsights\AiAssistant\Contracts\PermissionResolverInterface;
use ExcelleInsights\AiAssistant\Dto\ActionProposal;
use ExcelleInsights\AiAssistant\Dto\ActionResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use ExcelleInsights\AiAssistant\Services\ActionService;
use ExcelleInsights\AiAssistant\Services\OutboxService;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\Db;
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

/** The three tables these services write, as the migrations create them. */
function actionsDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ' . tbl('actions') . ' (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, name TEXT NOT NULL,
        version TEXT DEFAULT "1", label TEXT, description TEXT, permission TEXT,
        requires_confirmation INTEGER DEFAULT 1, definition_json TEXT, enabled INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE ' . tbl('action_runs') . ' (
        id TEXT PRIMARY KEY, company_id INTEGER DEFAULT 0, user_id TEXT, conversation_id TEXT,
        channel TEXT, action TEXT NOT NULL, params_json TEXT, preview_json TEXT,
        status TEXT DEFAULT "proposed", confirmation_token TEXT UNIQUE, token_expires_at TEXT,
        idempotency_key TEXT, result_json TEXT, error TEXT, approved_at TEXT, executed_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE ' . tbl('notification_outbox') . ' (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER DEFAULT 0, user_id TEXT,
        channel TEXT DEFAULT "email", recipient TEXT, subject TEXT, template TEXT, body TEXT,
        payload_json TEXT, attachments_json TEXT, status TEXT DEFAULT "pending",
        attempts INTEGER DEFAULT 0, error TEXT, next_attempt_at TEXT, sent_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');

    return $pdo;
}

/** A package table under whatever prefix this install is configured with. */
function tbl(string $suffix): string
{
    return Db::table($suffix);
}

/** Move a proposal's confirmation window into the past. */
function staleToken(PDO $pdo, string $id): void
{
    $pdo->prepare('UPDATE ' . tbl('action_runs') . ' SET token_expires_at = :past WHERE id = :id')
        ->execute(['past' => '2020-01-01 00:00:00', 'id' => $id]);
}

final class FakeHandler implements ActionHandlerInterface
{
    public int $executions = 0;
    /** @var array<int,array<string,mixed>> */
    public array $seen = [];
    public ?string $explodeOn = null;
    public bool $reject = false;

    public function actions(): array
    {
        return ['create_lead', 'move_deal'];
    }

    public function preview(UserContext $user, string $action, array $params): ActionProposal
    {
        if (!in_array($action, $this->actions(), true)) {
            return ActionProposal::invalid($action, ['this app has no action "' . $action . '"'], $params);
        }
        if (($params['name'] ?? '') === '') {
            return ActionProposal::invalid($action, ['a name is required'], $params);
        }

        return new ActionProposal(
            action: $action,
            params: $params,
            changes: [['field' => 'name', 'from' => null, 'to' => (string) $params['name']]],
            summary: 'Create a lead called ' . $params['name'],
            permission: 'crm.lead.create',
        );
    }

    public function execute(UserContext $user, string $action, array $params, string $idempotencyKey): ActionResult
    {
        $this->executions++;
        $this->seen[] = ['action' => $action, 'params' => $params, 'key' => $idempotencyKey, 'user' => $user->userId];
        if ($this->explodeOn === $action) {
            throw new RuntimeException('the host table write failed');
        }
        if ($this->reject) {
            return ActionResult::failure('a lead with that name already exists');
        }

        return ActionResult::success('Lead created', [['entity' => 'lead', 'id' => 123, 'label' => (string) ($params['name'] ?? '')]]);
    }
}

final class LeadOnlyPermissions implements PermissionResolverInterface
{
    /** @param string[] $allowed */
    public function __construct(private array $allowed = ['create_lead'])
    {
    }

    public function canView(UserContext $user, string $entity): bool
    {
        return true;
    }

    public function rowScope(UserContext $user, string $entity): ?string
    {
        return null;
    }

    public function hiddenColumns(UserContext $user, string $entity): array
    {
        return [];
    }

    public function canAct(UserContext $user, string $action): bool
    {
        return in_array($action, $this->allowed, true);
    }

    public function canExport(UserContext $user, string $entity): bool
    {
        return true;
    }
}

final class RecordingNotifier implements NotificationInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $sent = [];
    public bool $ok = true;
    public bool $explode = false;

    public function send(int $tenantId, string $channel, string $recipient, string $subject, string $body, array $attachments = []): array
    {
        $this->sent[] = compact('tenantId', 'channel', 'recipient', 'subject', 'body', 'attachments');
        if ($this->explode) {
            throw new RuntimeException('smtp refused to connect');
        }

        return ['ok' => $this->ok, 'error' => $this->ok ? null : 'mailbox full'];
    }
}

$alice = new UserContext('u1', 7, 'web', 'conv-1', ['agent']);
$other = new UserContext('u2', 7, 'web', 'conv-2', ['agent']);
$elsewhere = new UserContext('u1', 9, 'web', 'conv-3', ['admin']);

/** A notifier that always blows up in the transport. */
function explodingNotifier(): NotificationInterface
{
    $notifier = new RecordingNotifier();
    $notifier->explode = true;

    return $notifier;
}

// ─── step 1: proposing a change writes nothing ────────────────────────────────
$handler = new FakeHandler();
$mainDb = actionsDb();
$actions = new ActionService($mainDb, $handler);

$proposed = $actions->propose($alice, 'create_lead', ['name' => 'Alice'], ['conversation_id' => 'conv-1', 'channel' => 'web']);
same('propose: recorded as proposed', 'proposed', $proposed['status'] ?? '');
same('propose: nothing executed yet', 0, $handler->executions);
check('propose: a token comes back', strlen((string) ($proposed['confirm_token'] ?? '')) === 32, (string) ($proposed['confirm_token'] ?? ''));
check('propose: the token expires', (string) ($proposed['expires_at'] ?? '') > date('Y-m-d H:i:s'), json_encode($proposed));
check('propose: the preview is shown', str_contains((string) ($proposed['proposal']['summary'] ?? ''), 'Alice'), json_encode($proposed['proposal'] ?? null));
same('propose: confirmation is required', true, $proposed['requires_confirmation'] ?? false);
$stored = $actions->get((string) $proposed['id'], 7);
same('propose: the row is stored', 'create_lead', $stored['action'] ?? '');
check('propose: the token is not readable back out', !isset($stored['confirmation_token']), json_encode(array_keys((array) $stored)));
same('propose: the params are kept', 'Alice', $stored['params']['name'] ?? '');
same('propose: another tenant cannot see it', null, $actions->get((string) $proposed['id'], 9));

$invalid = $actions->propose($alice, 'create_lead', ['name' => '']);
same('propose: an invalid change never reaches confirm', 'invalid', $invalid['status'] ?? '');
check('propose: and says why', str_contains((string) $invalid['error'], 'name is required'), json_encode($invalid));
same('propose: unknown action rejected', 'invalid', $actions->propose($alice, 'fire_the_ceo', ['name' => 'Boss'])['status'] ?? '');

$noHandler = new ActionService(actionsDb());
$unregistered = $noHandler->propose($alice, 'create_lead', ['name' => 'Alice']);
same('propose: without a handler nothing is offered', 'refused', $unregistered['status'] ?? '');
check('propose: and it says the app has none', str_contains((string) $unregistered['error'], 'not registered'), json_encode($unregistered));
same('propose: an empty action name is refused', 'refused', $noHandler->propose($alice, ' ', [])['status'] ?? '');

$denied = new ActionService(actionsDb(), $handler, new LeadOnlyPermissions(['delete_customer']));
same('propose: permission resolver can stop it', 'refused', $denied->propose($alice, 'create_lead', ['name' => 'Alice'])['status'] ?? '');
$permitted = new ActionService(actionsDb(), $handler, new LeadOnlyPermissions());
same('propose: and let it through when allowed', 'proposed', $permitted->propose($alice, 'create_lead', ['name' => 'Alice'])['status'] ?? '');

// ─── the catalog is the host's opt-in ────────────────────────────────────────
$catalogDb = actionsDb();
$catalogDb->exec('INSERT INTO ' . tbl('actions') . ' (company_id, name, label, permission, enabled) VALUES (0, "create_lead", "Create lead", "crm.lead.create", 1)');
$catalogDb->exec('INSERT INTO ' . tbl('actions') . ' (company_id, name, label, permission, enabled) VALUES (7, "create_lead", "New prospect here", "crm.prospect", 0)');
$catalogued = new ActionService($catalogDb, $handler);
same('catalog: tenant row wins over global', 'New prospect here', $catalogued->catalog(7)['create_lead']['label'] ?? '');
same('catalog: the tenant permission travels', 'crm.prospect', $catalogued->catalog(7)['create_lead']['permission'] ?? '');
same('catalog: an unlisted action is still enabled', true, $catalogued->isEnabled('move_deal', 7));
same('catalog: a switched-off action is refused', false, $catalogued->isEnabled('create_lead', 7));
same('catalog: refused in propose too', 'refused', $catalogued->propose($alice, 'create_lead', ['name' => 'Alice'])['status'] ?? '');
check('catalog: and says it is off', str_contains((string) $catalogued->propose($alice, 'create_lead', ['name' => 'Alice'])['error'], 'switched off'), '');
same('catalog: another tenant is unaffected', true, $catalogued->isEnabled('create_lead', 8));
same('catalog: no database, everything the pack says', [], (new ActionService())->catalog(7));

// ─── step 2: confirm is the only way through ─────────────────────────────────
$confirmed = $actions->confirm((string) $proposed['confirm_token'], $alice);
same('confirm: executes once', true, $confirmed['ok'] ?? false);
same('confirm: executed status', 'executed', $confirmed['status'] ?? '');
same('confirm: the handler ran', 1, $handler->executions);
same('confirm: with the proposed params', 'Alice', $handler->seen[0]['params']['name'] ?? '');
same('confirm: the idempotency key is the run id', (string) $proposed['id'], $handler->seen[0]['key'] ?? '');
same('confirm: as the confirming user', 'u1', $handler->seen[0]['user'] ?? '');
$done = $actions->get((string) $proposed['id'], 7);
same('confirm: the row remembers', 'executed', $done['status'] ?? '');
check('confirm: and the result is stored', ($done['result']['records'][0]['id'] ?? null) == 123, json_encode($done['result'] ?? null));
check('confirm: approved and executed times set', ($done['approved_at'] ?? '') !== '' && ($done['executed_at'] ?? '') !== '', json_encode([$done['approved_at'] ?? null, $done['executed_at'] ?? null]));

$again = $actions->confirm((string) $proposed['confirm_token'], $alice);
same('confirm: a second click does not fire again', 1, $handler->executions);
same('confirm: and reports the first outcome', 'executed', $again['status'] ?? '');
same('confirm: marked as already handled', true, $again['already_handled'] ?? false);
same('confirm: returning the stored result', 'Lead created', $again['result']['message'] ?? '');

same('confirm: an unknown token is nothing', false, $actions->confirm('nope-nope-nope')['ok'] ?? true);
check('confirm: and says it is not recognised', str_contains((string) $actions->confirm('nope')['error'], 'not recognised'), '');
same('confirm: an empty token is refused', false, $actions->confirm('  ')['ok'] ?? true);

$second = $actions->propose($alice, 'create_lead', ['name' => 'Bob']);
same('confirm: another user cannot spend it', 'refused', $actions->confirm((string) $second['confirm_token'], $other)['status'] ?? '');
same('confirm: nobody else executed it', 1, $handler->executions);
$third = $actions->propose($alice, 'create_lead', ['name' => 'Carol']);
same('confirm: another tenant cannot either', 'refused', $actions->confirm((string) $third['confirm_token'], $elsewhere)['status'] ?? '');
$fourth = $actions->propose($alice, 'create_lead', ['name' => 'Dave']);
same('confirm: a host that already authenticated may pass null', true, $actions->confirm((string) $fourth['confirm_token'])['ok'] ?? false);

// the window matters: an old proposal must be re-confirmed
$fifth = $actions->propose($alice, 'create_lead', ['name' => 'Erin']);
staleToken($mainDb, (string) $fifth['id']);
$expired = $actions->confirm((string) $fifth['confirm_token'], $alice);
same('confirm: an expired token is not executed', 'expired', $expired['status'] ?? '');
same('confirm: expired says so plainly', true, str_contains(strtolower((string) $expired['error']), 'expired'));

// a host retried execute() with its own key — the second call returns the first
$sixth = $actions->propose($alice, 'move_deal', ['name' => 'Pipeline 4']);
$firstRun = $actions->confirm((string) $sixth['confirm_token'], $alice, 'crm-move-4');
same('idempotency: the host key is honoured', true, $firstRun['ok'] ?? false);
$moved = $actions->get((string) $sixth['id'], 7);
same('idempotency: stored with the run', 'crm-move-4', $moved['idempotency_key'] ?? '');

$before = $handler->executions;
$seventh = $actions->propose($alice, 'move_deal', ['name' => 'Pipeline 4']);
$replayed = $actions->confirm((string) $seventh['confirm_token'], $alice, 'crm-move-4');
same('idempotency: a retried key does not fire again', $before, $handler->executions);
same('idempotency: the first outcome comes back', true, $replayed['ok'] ?? false);
same('idempotency: flagged as a replay', true, $replayed['replayed'] ?? false);
same('idempotency: the other run is still a proposal', 'proposed', $actions->get((string) $seventh['id'], 7)['status'] ?? '');

// ─── failures are recorded, not swallowed ────────────────────────────────────
$bad = new FakeHandler();
$bad->explodeOn = 'create_lead';
$crashing = new ActionService(actionsDb(), $bad);
$crash = $crashing->propose($alice, 'create_lead', ['name' => 'Zoe']);
$crashResult = $crashing->confirm((string) $crash['confirm_token'], $alice);
same('execute throws: confirm fails cleanly', false, $crashResult['ok'] ?? true);
same('execute throws: status failed', 'failed', $crashResult['status'] ?? '');
$crashedRow = $crashing->get((string) $crash['id'], 7);
check('execute throws: the reason is stored', str_contains((string) ($crashedRow['error'] ?? ''), 'table write failed'), json_encode($crashedRow['error'] ?? null));

$rejecting = new FakeHandler();
$rejecting->reject = true;
$rejectedSvc = new ActionService(actionsDb(), $rejecting);
$rejectedProp = $rejectedSvc->propose($alice, 'create_lead', ['name' => 'Dupe']);
$rejected = $rejectedSvc->confirm((string) $rejectedProp['confirm_token'], $alice);
same('host refuses: reported as failed', 'failed', $rejected['status'] ?? '');
check('host refuses: with its own words', str_contains((string) $rejected['result']['message'], 'already exists'), json_encode($rejected['result'] ?? null));

$previewThrows = new ActionService(actionsDb(), new class implements ActionHandlerInterface {
    public function actions(): array
    {
        return ['create_lead'];
    }
    public function preview(UserContext $user, string $action, array $params): ActionProposal
    {
        throw new RuntimeException('the preview query timed out');
    }
    public function execute(UserContext $user, string $action, array $params, string $key): ActionResult
    {
        return ActionResult::success('never reached');
    }
});
$previewFailure = $previewThrows->propose($alice, 'create_lead', ['name' => 'Ann']);
same('preview throws: no proposal is offered', 'failed', $previewFailure['status'] ?? '');
check('preview throws: the error is passed on', str_contains((string) $previewFailure['error'], 'timed out'), json_encode($previewFailure));

// ─── cancelling and sweeping ─────────────────────────────────────────────────
$cancelable = $actions->propose($alice, 'create_lead', ['name' => 'Fay']);
same('cancel: a proposal can be dropped', true, $actions->cancel((string) $cancelable['id'], $alice));
same('cancel: its token is then useless', 'cancelled', $actions->confirm((string) $cancelable['confirm_token'], $alice)['status'] ?? '');
same('cancel: an executed run is not cancellable', false, $actions->cancel((string) $proposed['id']));
same('cancel: an unknown id is not cancellable', false, $actions->cancel('no-such-run'));

$sweepDb = actionsDb();
$sweep = new ActionService($sweepDb, $handler);
$one = $sweep->propose($alice, 'create_lead', ['name' => 'Gus']);
$two = $sweep->propose($alice, 'create_lead', ['name' => 'Hal']);
$rowId = (string) $one['id'];
staleToken($sweepDb, $rowId);
same('sweep: expired proposals are collected', 1, $sweep->expireStale());
same('sweep: the swept row is expired', 'expired', $sweep->get($rowId, 7)['status'] ?? '');
$tokenHandle = $sweepDb->prepare('SELECT confirmation_token FROM ' . tbl('action_runs') . ' WHERE id = :id');
$tokenHandle->execute(['id' => $rowId]);
same('sweep: and its token is burned', null, $tokenHandle->fetchColumn());
same('sweep: the live one still confirms', true, $sweep->confirm((string) $two['confirm_token'], $alice)['ok'] ?? false);
same('sweep: nothing left to sweep', 0, $sweep->expireStale());

$history = $actions->history(7);
check('history: newest first', count($history) > 1 && (string) $history[0]['created_at'] >= (string) $history[1]['created_at'], json_encode(array_slice(array_column($history, 'created_at'), 0, 2)));
same('history: scoped to the tenant', 0, count($actions->history(99)));
same('history: filterable by user', true, count($actions->history(7, 'u1')) <= count($history) && $actions->history(7, 'u1') !== []);
same('history: no user filter returns all', true, count($actions->history(7)) >= count($actions->history(7, 'nobody')));

// ─── pure rules, asserted without a database ─────────────────────────────────
$now = date('Y-m-d H:i:s');
same('rule: a fresh proposal is consumable', true, ActionService::consumable(['status' => 'proposed', 'confirmation_token' => 't', 'token_expires_at' => date('Y-m-d H:i:s', time() + 60)], $now));
same('rule: an expired one is not', false, ActionService::consumable(['status' => 'proposed', 'confirmation_token' => 't', 'token_expires_at' => '2020-01-01 00:00:00'], $now));
same('rule: a spent token is not', false, ActionService::consumable(['status' => 'proposed', 'confirmation_token' => '', 'token_expires_at' => null], $now));
same('rule: an executed run is not', false, ActionService::consumable(['status' => 'executed', 'confirmation_token' => 't', 'token_expires_at' => null], $now));
same('rule: a success is executed', 'executed', ActionService::statusOf(ActionResult::success('done')));
same('rule: a failure is failed', 'failed', ActionService::statusOf(ActionResult::failure('nope')));
same('rule: an array result reads ok', 'executed', ActionService::statusOf(['ok' => true]));
same('rule: legacy success key counts', 'executed', ActionService::statusOf(['success' => true]));
same('rule: nothing is a failure', 'failed', ActionService::statusOf(false));
$replay = ActionService::replayOf(['id' => 'r1', 'status' => 'executed', 'result_json' => '{"message":"Lead created"}']);
same('rule: replay reports the stored outcome', 'Lead created', $replay['result']['message'] ?? '');
same('rule: replay of a failure is not ok', false, ActionService::replayOf(['id' => 'r2', 'status' => 'failed'])['ok']);

// no database at all: the flow still works, there is simply no history
$headlessHandler = new FakeHandler();
$memory = new ActionService(null, $headlessHandler);
$ephemeral = $memory->propose($alice, 'create_lead', ['name' => 'Ivy']);
same('headless: a proposal still gets a token', 'proposed', $ephemeral['status'] ?? '');
same('headless: but confirm cannot find it', 'invalid', $memory->confirm((string) $ephemeral['confirm_token'], $alice)['status'] ?? '');
same('headless: nothing was executed', 0, $headlessHandler->executions);
same('headless: and no history to read', 0, count($memory->history(7)));

// ─── outbox: queueing ────────────────────────────────────────────────────────
$outboxDb = actionsDb();
$sent = new RecordingNotifier();
$outbox = new OutboxService($outboxDb, $sent);

$queued = $outbox->queue(7, ['channel' => 'email', 'recipient' => 'boss@ex.am', 'subject' => 'Weekly report', 'body' => 'Attached'], 'u1');
same('outbox: queued as pending', 'pending', $queued['status'] ?? '');
check('outbox: gets an id', (string) ($queued['id'] ?? '') !== '', json_encode($queued));
same('outbox: no recipient is rejected', false, $outbox->queue(7, ['channel' => 'email'])['ok'] ?? true);
check('outbox: and says why', str_contains((string) $outbox->queue(7, [])['error'], 'recipient'), '');
$many = $outbox->queueMany(7, [['recipient' => 'a@ex.am', 'body' => 'one'], ['recipient' => 'b@ex.am', 'body' => 'two'], ['channel' => 'email']]);
same('outbox: queueMany counts the accepted', 2, $many['queued'] ?? 0);
$due = $outbox->due();
same('outbox: three due now', 3, count($due));
same('outbox: hydrated payload', [], $due[0]['payload'] ?? 'x');
$scheduled = $outbox->queue(7, ['recipient' => 'later@ex.am', 'body' => 'not yet', 'scheduled_at' => date('Y-m-d H:i:s', time() + 3600)]);
same('outbox: a future message is not due', 3, count($outbox->due()));
$nullScheduled = $outbox->queue(7, ['recipient' => 'urgent@ex.am', 'body' => 'send', 'scheduled_at' => null]);
same('outbox: no schedule means immediately due', 4, count($outbox->due()));

// ─── outbox: sending and retrying ────────────────────────────────────────────
$results = $outbox->flush();
same('flush: everything due went out', 4, count($results));
same('flush: the notifier saw each one', 4, count($sent->sent));
same('flush: with the right recipient', 'boss@ex.am', $sent->sent[0]['recipient'] ?? '');
same('flush: and the tenant', 7, $sent->sent[0]['tenantId'] ?? 0);
same('flush: channel travels', 'email', $sent->sent[0]['channel'] ?? '');
same('flush: nothing left pending', 0, count($outbox->due()));
$afterFlush = $outbox->get((string) $queued['id']);
same('flush: the row is marked sent', 'sent', $afterFlush['status'] ?? '');
check('flush: sent_at recorded', (string) ($afterFlush['sent_at'] ?? '') !== '', json_encode(array_keys((array) $afterFlush)));
same('flush: one attempt', 1, (int) ($afterFlush['attempts'] ?? 0));
same('flush: a second flush sends nothing', 0, count($outbox->flush()));

$failing = new RecordingNotifier();
$failing->ok = false;
$retryDb = actionsDb();
$retries = new OutboxService($retryDb, $failing);
$job = $retries->queue(7, ['recipient' => 'c@ex.am', 'body' => 'will fail']);
$first = $retries->flush();
same('retry: a refusal is not a send', false, $first[0]['ok'] ?? true);
same('retry: still pending for another go', 'pending', $first[0]['status'] ?? '');
same('retry: attempt counted', 1, (int) $first[0]['attempts']);
same('retry: the reason kept', 'mailbox full', (string) ($retries->get((string) $job['id'])['error'] ?? ''));
same('retry: not due again yet', 0, count($retries->due(50, date('Y-m-d H:i:s'))));
$row = $retries->get((string) $job['id']);
check('retry: parked for the backoff window', (string) $row['next_attempt_at'] > date('Y-m-d H:i:s'), (string) ($row['next_attempt_at'] ?? ''));

Config::set('outbox.max_attempts', 3);
$exhaust = new OutboxService(actionsDb(), static fn (): array => ['ok' => false, 'error' => 'no route to host']);
$last = $exhaust->queue(7, ['recipient' => 'd@ex.am', 'body' => 'stubborn']);
$statuses = [];
for ($i = 0; $i < 4; $i++) {
    $pending = $exhaust->due(50, date('Y-m-d H:i:s', time() + 86400));
    if ($pending === []) {
        $statuses[] = 'not-due';
        continue;
    }
    $outcome = $exhaust->deliver($pending[0]);
    $statuses[] = (string) $outcome['status'];
}
same('retry: gives up after the configured attempts', 'pending|pending|failed|not-due', implode('|', $statuses));
$given = $exhaust->get((string) $last['id']);
same('retry: the exhausted row is failed', 'failed', $given['status'] ?? '');
same('retry: with the last error', 'no route to host', (string) ($given['error'] ?? ''));
Config::set('outbox.max_attempts', 5);

$throwing = new OutboxService(actionsDb(), explodingNotifier());
$thrown = $throwing->queue(7, ['recipient' => 'e@ex.am', 'body' => 'boom']);
$thrownResult = $throwing->flush();
same('sender throws: counted as a failure', false, $thrownResult[0]['ok'] ?? true);
check('sender throws: the message is kept', str_contains((string) $thrownResult[0]['error'], 'smtp'), json_encode($thrownResult));

$silent = new OutboxService(actionsDb());
$unsent = $silent->queue(7, ['recipient' => 'f@ex.am', 'body' => 'nobody to send it']);
$held = $silent->flush();
same('no sender: the message is not lost', false, $held[0]['ok'] ?? true);
same('no sender: and stays pending', 'pending', $silent->get((string) $unsent['id'])['status'] ?? '');
throws('no sender: a non-notifier is refused', fn () => $silent->setSender('this is not callable'), 'callable');

$cancellable = $silent->queue(7, ['recipient' => 'g@ex.am', 'body' => 'withdrawn']);
$pendingBefore = count($silent->due());
same('cancel: a pending message can be dropped', true, $silent->cancel((string) $cancellable['id']));
same('cancel: it is no longer due', $pendingBefore - 1, count($silent->due()));
same('cancel: twice is not a change', false, $silent->cancel((string) $cancellable['id']));
same('cancel: unknown id', false, $silent->cancel('99999'));

$counts = $silent->counts(7);
same('counts: pending and cancelled are tallied', 1, $counts['cancelled'] ?? 0);
same('counts: another tenant sees nothing', 0, $silent->counts(8)['total'] ?? 1);
same('counts: no database, no queue', 0, (new OutboxService())->due() === [] ? 0 : 1);

// ─── outbox pure rules ───────────────────────────────────────────────────────
$at = time();
same('backoff: first retry is one window', date('Y-m-d H:i:s', $at + 60), OutboxService::retryAt(1, 60, $at));
same('backoff: doubling', date('Y-m-d H:i:s', $at + 240), OutboxService::retryAt(3, 60, $at));
same('backoff: capped at two hours', date('Y-m-d H:i:s', $at + 7200), OutboxService::retryAt(20, 60, $at));
same('backoff: a custom window', date('Y-m-d H:i:s', $at + 5), OutboxService::retryAt(1, 5, $at));
$payload = OutboxService::payloadOf(['id' => 4, 'company_id' => 7, 'channel' => 'whatsapp', 'recipient' => '5511', 'subject' => '', 'body' => 'x', 'payload_json' => '{"report_id":"r1"}', 'attachments_json' => '["a.csv"]']);
same('payload: id travels as a string', '4', $payload['id']);
same('payload: attachments decoded', 'a.csv', $payload['attachments'][0] ?? '');
same('payload: template optional', null, OutboxService::payloadOf([])['template']);

Config::reset();
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
