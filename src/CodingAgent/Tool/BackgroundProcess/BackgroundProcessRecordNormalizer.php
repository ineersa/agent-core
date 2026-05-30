<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Symfony Denormalizer that converts a DB row array into a
 * BackgroundProcessRecord DTO with proper type coercions.
 *
 * Autoconfigured via DenormalizerInterface — no manual service wiring needed.
 */
final class BackgroundProcessRecordNormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        \assert(\is_array($data));

        return new BackgroundProcessRecord(
            id: $this->intField($data, 'id'),
            pid: $this->intField($data, 'pid'),
            pgid: $this->nullableIntField($data, 'pgid'),
            command: $this->stringField($data, 'command'),
            logPath: $this->stringField($data, 'log_path'),
            startedAt: $this->stringField($data, 'started_at'),
            finishedAt: $this->nullableStringField($data, 'finished_at'),
            exitCode: $this->nullableIntField($data, 'exit_code'),
            stoppedByUser: (bool) ($data['stopped_by_user'] ?? false),
            sessionId: $this->stringField($data, 'session_id'),
            status: $this->stringField($data, 'status'),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return BackgroundProcessRecord::class === $type && \is_array($data);
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [BackgroundProcessRecord::class => true];
    }

    /**
     * Extract and cast an integer field from a DB row.
     *
     * @param array<string, mixed> $row
     */
    private function intField(array $row, string $field): int
    {
        $val = $row[$field] ?? 0;

        return \is_int($val) || (\is_string($val) && ctype_digit($val)) ? (int) $val : 0;
    }

    /**
     * Extract and cast a nullable integer field from a DB row.
     *
     * @param array<string, mixed> $row
     */
    private function nullableIntField(array $row, string $field): ?int
    {
        $val = $row[$field] ?? null;

        if (null === $val) {
            return null;
        }

        return \is_int($val) || (\is_string($val) && ctype_digit($val)) ? (int) $val : null;
    }

    /**
     * Extract and cast a string field from a DB row.
     *
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $field): string
    {
        $val = $row[$field] ?? '';

        return \is_string($val) ? $val : (string) $val;
    }

    /**
     * Extract and cast a nullable string field from a DB row.
     *
     * @param array<string, mixed> $row
     */
    private function nullableStringField(array $row, string $field): ?string
    {
        $val = $row[$field] ?? null;

        return \is_string($val) ? $val : null;
    }
}
