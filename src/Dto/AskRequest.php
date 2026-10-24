<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * One turn of conversation, as it reaches AssistantService::ask().
 */
final readonly class AskRequest
{
    /** Formats recognised in a question — which of them can be built is the host's call. */
    private const EXPORT_FORMATS = ['csv', 'tsv', 'json', 'xlsx', 'xls', 'pdf', 'docx'];

    /**
     * @param array<int,array{role:string,content:string}> $history Override history; empty = read from the conversation store
     * @param array<string,mixed> $context  Page/record the user is looking at ("summarise this customer")
     * @param array<string,mixed> $options  intent, k, chart, export, locale, temperature…
     */
    public function __construct(
        public string $question,
        public UserContext $user,
        public array $history = [],
        public array $context = [],
        public array $options = [],
    ) {
    }

    public static function make(string $question, UserContext|array $user, array $options = []): self
    {
        return new self(
            question: trim($question),
            user: is_array($user) ? UserContext::fromArray($user) : $user,
            options: $options,
        );
    }

    public function intentHint(): string
    {
        return strtolower((string) ($this->options['intent'] ?? 'auto'));
    }

    public function wantsChart(): bool
    {
        return (bool) ($this->options['chart'] ?? false);
    }

    /**
     * The file format this question asks for, or '' when no file was asked for.
     * "export these to excel" is xlsx, "give me a csv" is csv, "download this"
     * is the plainest thing we can always produce: csv.
     */
    public function exportFormat(): string
    {
        $explicit = strtolower(trim((string) ($this->options['export'] ?? '')));
        if ($explicit !== '' && $explicit !== '0' && $explicit !== 'false') {
            return in_array($explicit, self::EXPORT_FORMATS, true) ? $explicit : 'csv';
        }

        preg_match_all('/\b(csv|excel|xlsx|xls|tsv|json|pdf|docx|spreadsheet|download|export)\b/', strtolower($this->question), $found);
        $words = $found[0] ?? [];
        if ($words === []) {
            return '';
        }
        // A named format beats the bare words "export"/"download": "export the
        // leads to excel" is xlsx, not the default.
        $named = array_intersect($words, ['csv', 'tsv', 'json', 'xlsx', 'xls', 'pdf', 'docx']);
        if ($named !== []) {
            return (string) reset($named);
        }
        if (in_array('excel', $words, true) || in_array('spreadsheet', $words, true)) {
            return 'xlsx';
        }

        return in_array('export', $words, true) || in_array('download', $words, true) ? 'csv' : '';
    }

    public function maxRows(): int
    {
        return (int) ($this->options['max_rows'] ?? 0);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
