<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * The assistant's reply, ready for any channel. Renderers pick what they need:
 * a WhatsApp adapter uses text(), the web widget uses table/chart/actions.
 */
final readonly class Answer
{
    /**
     * @param string[] $suggestions follow-up prompts to offer
     * @param Citation[] $citations
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $chart declarative chart spec (ChartService output)
     * @param array<string,mixed>|null $pendingAction action awaiting user confirmation
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public string $text,
        public string $intent = 'smalltalk',
        public array $rows = [],
        public array $chart = [],
        public array $citations = [],
        public ?string $sql = null,
        public array $appliedFilters = [],
        public array $suggestions = [],
        public ?array $pendingAction = null,
        public bool $grounded = true,
        public array $meta = [],
    ) {
    }

    public static function text(string $text, string $intent = 'smalltalk', array $citations = []): self
    {
        return new self(text: $text, intent: $intent, citations: $citations);
    }

    public function hasData(): bool
    {
        return $this->rows !== [];
    }

    public function hasChart(): bool
    {
        return $this->chart !== [];
    }

    /** @return array<int,array<string,string>> type/label/ref, for the UI "sources" strip */
    public function sources(): array
    {
        $out = [];
        foreach ($this->citations as $c) {
            $out[] = ['type' => $c->type, 'label' => $c->label, 'ref' => (string) $c->ref];
        }
        return $out;
    }

    /**
     * Plain-text rendering for channels with no rich support.
     *
     * @param bool $withSources false for surfaces that show sources elsewhere
     * @param int  $maxRows     rows printed before "… n more rows"
     */
    public function plain(bool $withSources = true, int $maxRows = 15): string
    {
        $text = $this->text;
        if ($this->rows !== []) {
            $text .= "\n\n" . $this->rowsText($maxRows);
        }
        if ($withSources && $this->citations !== []) {
            $labels = [];
            foreach ($this->citations as $c) {
                $labels[] = $c->label;
            }
            $text .= "\n\nSources: " . implode('; ', array_unique($labels));
        }
        return $text;
    }

    /** @param int $max rows to print before the "more rows" line */
    public function rowsText(int $max = 15): string
    {
        $rows = array_slice($this->rows, 0, $max);
        $lines = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($row as $key => $value) {
                $parts[] = $key . ': ' . (is_scalar($value) || $value === null ? (string) $value : json_encode($value));
            }
            $lines[] = '- ' . implode(' | ', $parts);
        }
        if (count($this->rows) > $max) {
            $lines[] = '- … ' . (count($this->rows) - $max) . ' more rows';
        }
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> JSON-safe shape for widget/API hosts */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'intent' => $this->intent,
            'grounded' => $this->grounded,
            'rows' => $this->rows,
            'chart' => $this->chart ?: null,
            'citations' => array_map(fn (Citation $c) => $c->toArray(), $this->citations),
            'sql' => $this->sql,
            'applied_filters' => $this->appliedFilters ?: null,
            'suggestions' => $this->suggestions ?: null,
            'pending_action' => $this->pendingAction,
            'meta' => $this->meta + ['conversation_id' => null],
        ];
    }
}
