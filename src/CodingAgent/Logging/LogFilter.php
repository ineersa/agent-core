<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Specification-pattern filter for log entries.
 *
 * All filter fields are optional. A null field means "match everything."
 * Multiple non-null fields are combined with AND semantics.
 */
final readonly class LogFilter
{
    public function __construct(
        public ?string $level = null,
        public ?string $search = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?int $limit = null,
    ) {
    }

    /**
     * Returns true when $entry satisfies all configured criteria.
     */
    public function matches(LogEntry $entry): bool
    {
        if (null !== $this->level && strtoupper($entry->level) !== strtoupper($this->level)) {
            return false;
        }

        if (null !== $this->from && $entry->datetime < $this->from) {
            return false;
        }

        if (null !== $this->to && $entry->datetime > $this->to) {
            return false;
        }

        if (null !== $this->search) {
            $term = mb_strtolower($this->search);

            if (
                !str_contains(mb_strtolower($entry->message), $term)
                && !$this->contextContainsTerm($entry->context, $term)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively search through context array for a case-insensitive substring.
     *
     * @param array<array-key, mixed> $context
     */
    private function contextContainsTerm(array $context, string $term): bool
    {
        foreach ($context as $value) {
            if (\is_string($value) && str_contains(mb_strtolower($value), $term)) {
                return true;
            }

            if (\is_array($value) && $this->contextContainsTerm($value, $term)) {
                return true;
            }
        }

        return false;
    }
}
