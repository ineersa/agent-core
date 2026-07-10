<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Reads the highest committed seq from an append-only JSONL event log under an existing lock.
 *
 * Uses tail scanning so sequenced appends do not scan the full file on every write.
 */
final class EventLogLastSeqReader
{
    private const int TAIL_CHUNK_BYTES = 8192;

    public function readLastSeqLocked(string $path): int
    {
        if (!is_readable($path)) {
            return 0;
        }

        $size = filesize($path);
        if (false === $size || 0 === $size) {
            return 0;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return 0;
        }

        try {
            $lastLine = $this->readLastNonEmptyLine($handle, $size);
            if (null === $lastLine) {
                return 0;
            }

            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($lastLine, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new EventLogLastSequenceException(\sprintf('Last non-empty line in event log "%s" is not valid JSON.', $path), previous: $e);
            }

            $seq = $payload['seq'] ?? null;
            if (!\is_int($seq)) {
                throw new EventLogLastSequenceException(\sprintf('Last non-empty line in event log "%s" is missing an integer seq field.', $path));
            }

            return $seq;
        } finally {
            fclose($handle);
        }
    }

    private function readLastNonEmptyLine(mixed $handle, int $fileSize): ?string
    {
        $buffer = '';
        $offset = $fileSize;

        while ($offset > 0) {
            $readSize = min(self::TAIL_CHUNK_BYTES, $offset);
            $offset -= $readSize;
            if (-1 === fseek($handle, $offset)) {
                break;
            }

            $chunk = fread($handle, $readSize);
            if (false === $chunk || '' === $chunk) {
                break;
            }

            $buffer = $chunk.$buffer;
            foreach ($this->nonEmptyLinesFromTail($buffer) as $line) {
                return $line;
            }
        }

        foreach ($this->nonEmptyLinesFromTail($buffer) as $line) {
            return $line;
        }

        return null;
    }

    /** @return list<string> */
    private function nonEmptyLinesFromTail(string $buffer): array
    {
        $lines = preg_split("/\r?\n/", rtrim($buffer, "\r\n"));
        if (false === $lines) {
            $lines = [];
        }
        for ($index = \count($lines) - 1; $index >= 0; --$index) {
            $trimmed = trim($lines[$index]);
            if ('' !== $trimmed) {
                return [$trimmed];
            }
        }

        return [];
    }
}
