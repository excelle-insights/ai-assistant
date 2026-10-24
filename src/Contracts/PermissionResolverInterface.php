<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

use ExcelleInsights\AiAssistant\Dto\UserContext;

/**
 * Host-side authorisation. The assistant asks three questions only:
 * may this user see this entity, run this action, export this data?
 *
 * Anything row or field level is the host's decision — the package cannot know
 * its row-scope rules, and guessing them would be a security bug.
 */
interface PermissionResolverInterface
{
    public function canView(UserContext $user, string $entity): bool;

    /**
     * SQL fragment the package must inject into generated queries for row scope
     * (e.g. "assigned_user_id = 7"), or null when the host answers the query
     * itself through the `rows` hook. Return '' for no extra restriction.
     */
    public function rowScope(UserContext $user, string $entity): ?string;

    /**
     * Columns to hide entirely for this user (field-level masking beyond the
     * configured policy).
     */
    public function hiddenColumns(UserContext $user, string $entity): array;

    public function canAct(UserContext $user, string $action): bool;

    public function canExport(UserContext $user, string $entity): bool;
}
