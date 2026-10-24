<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Dto\UserContext;
use PDO;

/**
 * Executes the parameterised SQL QueryCompiler produces.
 *
 * AssistantService never holds a connection: it calls a 'rows' hook. Pass this
 * invokable as that hook when the assistant may read the database itself, or
 * write your own when answers should come from your repositories instead.
 *
 *   new AssistantService(hooks: ['rows' => new SqlQueryRunner($pdo)]);
 *
 * The statement is always a single SELECT with bound values only. The read-only
 * assertion below is a second line of defence behind QueryCompiler, not a
 * substitute for the permission checks the host injects as scope.
 */
final class SqlQueryRunner
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $compiled one QueryCompiler::compile() result
     */
    public function __invoke(array $compiled, ?UserContext $user = null): QueryResult
    {
        $sql = trim((string) ($compiled['sql'] ?? ''));
        if ($sql === '') {
            return QueryResult::fromRows([], null, 'semantic');
        }
        self::assertReadOnly($sql);

        $statement = $this->pdo->prepare($sql);
        foreach ((array) ($compiled['bind'] ?? []) as $key => $value) {
            $name = str_starts_with((string) $key, ':') ? (string) $key : ':' . $key;
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $limit = (int) ($compiled['limit'] ?? 0);

        return new QueryResult(
            columns: array_values((array) ($compiled['columns'] ?? (isset($rows[0]) ? array_keys((array) $rows[0]) : []))),
            rows: array_values($rows),
            sql: $sql,
            appliedFilters: (array) ($compiled['applied_filters'] ?? []),
            truncated: $limit > 0 && count($rows) >= $limit,
            source: 'semantic',
        );
    }

    /** SQL the compiler built is a single SELECT; anything else is a bug or an attack. */
    private static function assertReadOnly(string $sql): void
    {
        if (preg_match('/^\s*(\(|SELECT\b|WITH\b)/i', $sql) !== 1) {
            throw new \RuntimeException('Refusing to run a statement that is not a SELECT.');
        }
        if (str_contains($sql, ';')) {
            throw new \RuntimeException('Refusing to run more than one statement.');
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|GRANT|REVOKE|TRUNCATE|RENAME|CALL|EXEC|EXECUTE|HANDLER|LOCK|UNLOCK|LOAD_FILE|OUTFILE|DUMPFILE|SLEEP|BENCHMARK)\b/i', $sql) === 1) {
            throw new \RuntimeException('Refusing to run a statement that can write or block.');
        }
    }
}
