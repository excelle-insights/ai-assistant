<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * One retrieval result that behaves like an associative row *and* like the
 * plain text a prompt needs.
 *
 * Why: `KnowledgeService::search()` historically returned strings (callers
 * `implode("\n---\n", $chunks)` them into a prompt), while hosts and this
 * package's HTTP layer want structured rows with question/answer/score. A
 * SearchHit satisfies both without an "Array to string conversion" warning:
 *
 *   $row['question']   // field access
 *   (string) $row      // "question: answer" prompt text
 *   implode("\n", $rows)
 *   json_encode($rows) // serialises as an object/array, not as an object blob
 *
 * It is read-only in spirit: offsetSet/offsetUnset exist only so ArrayAccess
 * is fully implemented.
 */
final class SearchHit implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable, \Stringable
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data = [], private readonly string $text = '')
    {
        $this->data = $data;
    }

    /** @param array<string,mixed> $data */
    public static function make(array $data, string $text = ''): self
    {
        return new self($data, $text);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function text(): string
    {
        if ($this->text !== '') {
            return $this->text;
        }
        return self::render($this->data);
    }

    /** Build the legacy prompt line: "[category] question: answer". */
    public static function render(array $row): string
    {
        $category = trim((string) ($row['category'] ?? ''));
        $question = trim((string) ($row['question'] ?? $row['title'] ?? ''));
        $answer = trim((string) ($row['answer'] ?? $row['content'] ?? ''));
        if ($answer === '' && $question === '') {
            return trim((string) ($row['content'] ?? $row['text'] ?? ''));
        }
        $head = ($category !== '' ? '[' . $category . '] ' : '') . $question;
        return $head !== '' ? $head . ': ' . $answer : $answer;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
            return;
        }
        $this->data[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function __toString(): string
    {
        return $this->text();
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}
