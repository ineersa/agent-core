<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/**
 * Parses JSONL log lines into LogEntry value objects.
 *
 * Gracefully handles non-JSON and malformed lines by returning null,
 * so the reader can skip them without throwing.
 */
final readonly class LogParser
{
    /**
     * Parse a single JSONL line into a LogEntry, or null if parsing fails.
     *
     * @param string      $line       Raw JSON line (may contain trailing whitespace)
     * @param string|null $sourceFile File the line was read from (for traceability)
     * @param int|null    $lineNumber 1-based line number in the source file
     */
    public function parse(string $line, ?string $sourceFile = null, ?int $lineNumber = null): ?LogEntry
    {
        $trimmed = trim($line);
        if ('' === $trimmed) {
            return null;
        }

        try {
            $data = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        $datetime = $this->parseDateTime($data['datetime'] ?? null);
        if (null === $datetime) {
            return null;
        }

        $channel = (string) ($data['channel'] ?? 'app');
        $level = (string) ($data['level_name'] ?? $data['level'] ?? 'INFO');
        $message = (string) ($data['message'] ?? '');
        $context = \is_array($data['context'] ?? null) ? $data['context'] : [];
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];

        return new LogEntry(
            datetime: $datetime,
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
            sourceFile: $sourceFile,
            lineNumber: $lineNumber,
        );
    }

    /**
     * Parse a datetime value from JSON (ISO 8601 string or numeric timestamp).
     */
    private function parseDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }

        if ($raw instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($raw);
        }

        if (\is_int($raw) || \is_float($raw)) {
            return (new \DateTimeImmutable())->setTimestamp((int) $raw);
        }

        if (\is_string($raw) && '' !== $raw) {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\DateMalformedStringException) {
                return null;
            }
        }

        return null;
    }
}
