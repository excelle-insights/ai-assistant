<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * Text (plus optional page/structure map) produced by a TextExtractorInterface.
 */
final readonly class ExtractedDocument
{
    /**
     * @param array<int,array{page:int,text:string}> $pages
     * @param array<int,array<string,mixed>> $tables
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public string $text,
        public array $pages = [],
        public array $tables = [],
        public ?string $language = null,
        public int $pageCount = 0,
        public array $meta = [],
    ) {
    }

    public static function fromText(string $text, array $meta = []): self
    {
        return new self(text: $text, pageCount: 1, meta: $meta);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function wordCount(): int
    {
        return str_word_count(strip_tags($this->text));
    }

    /** Naive but dependency-free language guess, for logging and prompt hints. */
    public function detectLanguage(): ?string
    {
        if ($this->language !== null) {
            return $this->language;
        }
        $sample = mb_substr($this->text, 0, 2000);
        if (preg_match('/\b(hamisi|asante|kwa|habari|karibu|nafasi|lipa)\b/i', $sample)) {
            return 'sw';
        }
        if (preg_match('/\b(bonjour|merci|vous|avec|pour|entreprise)\b/i', $sample)) {
            return 'fr';
        }
        return 'en';
    }
}
