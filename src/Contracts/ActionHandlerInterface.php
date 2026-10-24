<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\ActionProposal;
use ExcelleInsights\AiAssistant\Dto\ActionResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;

/**
 * Write operations ("add a lead", "move this deal to Proposal") are always
 * two-step and always executed by the host:
 *
 *   propose()  — the package parses intent, this returns what WOULD change
 *   confirm()  — the user approves; the package mints a single-use token
 *   execute()  — only then does the host write to its own tables
 *
 * The package never inserts, updates or deletes a host row.
 */
interface ActionHandlerInterface
{
    /** Action names this handler implements (must match the domain pack). */
    public function actions(): array;

    /** Read-only preview of the change, for the confirmation card. */
    public function preview(UserContext $user, string $action, array $params): ActionProposal;

    /** Run the change. Called only with a valid confirmation token. */
    public function execute(UserContext $user, string $action, array $params, string $idempotencyKey): ActionResult;
}
