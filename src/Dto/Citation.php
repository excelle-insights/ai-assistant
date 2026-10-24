<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * Where an answer came from. Every factual answer should carry at least one
 * citation; an answer with none is the assistant guessing and must say so.
 */
final readonly class Citation
{
    public function __construct(
        /** knowledge | document | record | query | reference | schema | web */
        public string $type,
        public string $label,
        public ?string $ref = null,
        public array $detail = [],
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'label' => $this->label,
            'ref' => $this->ref,
            'detail' => $this->detail ?: null,
        ], fn ($v) => $v !== null);
    }
}
