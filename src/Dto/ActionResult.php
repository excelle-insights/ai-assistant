<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/** What the host did after a confirmed action. */
final readonly class ActionResult
{
    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $records affected host records, e.g. [['entity'=>'lead','id'=>123,'label'=>'John Kamau']]
     */
    public function __construct(
        public bool $ok,
        public string $message = '',
        public array $records = [],
        public array $data = [],
        public ?string $error = null,
    ) {
    }

    public static function success(string $message, array $records = [], array $data = []): self
    {
        return new self(true, $message, $records, $data);
    }

    public static function failure(string $error, string $message = ''): self
    {
        return new self(false, $message !== '' ? $message : $error, [], [], $error);
    }

    public function toArray(): array
    {
        // `data` is what the host made — a new id, a link, a count — and the caller
        // needs it to say "lead 42 is created" rather than only "done".
        return ['ok' => $this->ok, 'message' => $this->message, 'records' => $this->records, 'data' => $this->data, 'error' => $this->error];
    }
}
