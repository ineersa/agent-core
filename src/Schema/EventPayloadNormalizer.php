<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Schema;

use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class EventPayloadNormalizer
{
    private EventNameMap $eventNameMap;

    public function __construct(?EventNameMap $eventNameMap = null)
    {
        $this->eventNameMap = $eventNameMap ?? new EventNameMap();
    }

    /**
     * Converts a RunEvent into the canonical serialized payload shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeRunEvent(RunEvent $event): array
    {
        return $this->normalize(
            runId: $event->runId,
            seq: $event->seq,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $event->payload,
            ts: $event->createdAt,
        );
    }

    /**
     * Builds a serialized event envelope from primitive event fields.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function normalize(
        string $runId,
        int $seq,
        int $turnNo,
        string $type,
        array $payload = [],
        ?\DateTimeImmutable $ts = null,
    ): array {
        $createdAt = $ts ?? new \DateTimeImmutable();

        return [
            'schema_version' => SchemaVersion::CURRENT,
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => $turnNo,
            'type' => $this->toPublicType($type),
            'payload' => $payload,
            'ts' => $createdAt->format(\DATE_ATOM),
        ];
    }

    /**
     * Hydrates a RunEvent from a serialized envelope when the schema is compatible.
     *
     * @param array<string, mixed> $payload
     */
    public function denormalizeRunEvent(array $payload): ?RunEvent
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (null !== $schemaVersion) {
            if (!\is_string($schemaVersion) || !$this->isCompatibleSchemaVersion($schemaVersion)) {
                return null;
            }
        }

        $runId = $payload['run_id'] ?? null;
        $seq = $payload['seq'] ?? null;
        $turnNo = $payload['turn_no'] ?? null;
        $type = $payload['type'] ?? null;
        $eventPayload = $payload['payload'] ?? null;

        if (!\is_string($runId)
            || !\is_int($seq)
            || !\is_int($turnNo)
            || !\is_string($type)
            || !\is_array($eventPayload)) {
            return null;
        }

        $createdAt = null;
        if (\is_string($payload['ts'] ?? null)) {
            try {
                $createdAt = new \DateTimeImmutable($payload['ts']);
            } catch (\Throwable) {
            }
        }

        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $this->toInternalType($type),
            payload: $eventPayload,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function toPublicType(string $internalType): string
    {
        return $this->eventNameMap->toPublic($internalType);
    }

    public function toInternalType(string $publicType): string
    {
        return $this->eventNameMap->toInternal($publicType);
    }

    private function isCompatibleSchemaVersion(string $schemaVersion): bool
    {
        $expectedMajor = explode('.', SchemaVersion::CURRENT, 2)[0];
        $candidateMajor = explode('.', $schemaVersion, 2)[0];

        return '' !== $candidateMajor && $candidateMajor === $expectedMajor;
    }
}
