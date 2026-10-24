<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * The preview shown before a write happens ("Add lead John Kamau → creates
 * leads row with …"). RequiresConfirmation defaults to true: an action that
 * changes data must never run without the user approving this payload.
 */
final readonly class ActionProposal
{
    /**
     * @param array<string,mixed> $params
     * @param array<int,array{field:string,before:mixed,after:mixed}> $changes
     */
    public function __construct(
        public string $action,
        public array $params = [],
        public array $changes = [],
        public string $summary = '',
        public bool $requiresConfirmation = true,
        public bool $valid = true,
        public array $errors = [],
        public ?string $permission = null,
        public int $affectedRows = 1,
    ) {
    }

    public static function invalid(string $action, array $errors, array $params = []): self
    {
        return new self(action: $action, params: $params, valid: false, errors: $errors, summary: 'Could not run: ' . implode('; ', $errors));
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'params' => $this->params,
            'changes' => $this->changes,
            'summary' => $this->summary,
            'requires_confirmation' => $this->requiresConfirmation,
            'valid' => $this->valid,
            'errors' => $this->errors,
            'permission' => $this->permission,
            'affected_rows' => $this->affectedRows,
        ];
    }
}
