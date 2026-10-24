<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Contracts;

/**
 * Optional host hook for applications whose table/column policy is not a flat
 * list — for example a CRM where a table is queryable only for the rows the
 * signed-in user may see, or where a column is readable by finance but not by
 * sales.
 *
 * Returning null means "no opinion" and the package falls back to the
 * configured allowlist/denylist. Implementations must be cheap: the package
 * calls this per table while building prompt context, not per row.
 */
interface TablePolicyInterface
{
    /**
     * @param array<string,mixed> $context Host context, e.g. ['user_id'=>7,'role'=>'manager','tenant_id'=>3]
     */
    public function allowsTable(string $table, array $context = []): ?bool;

    public function allowsColumn(string $table, string $column, array $context = []): ?bool;

    /** Column names to deny on top of the configured blocked_columns list. */
    public function extraBlockedColumns(array $context = []): array;
}
