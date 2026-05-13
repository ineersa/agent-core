<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Normalized runtime event emitted by the agent runtime and consumed by the TUI or JSONL client.
 *
 * This is the canonical event shape used by both in-process and process transports.
 * In JSONL mode, it maps directly to a JSON-line stanza.
 */
final readonly class RuntimeEvent
{
    private const int VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public string $runId,
        public int $seq,
        public array $payload = [],
        public int $v = self::VERSION,
    ) {
    }

    /**
     * @return array{v: int, type: string, runId: string, seq: int, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'type' => $this->type,
            'runId' => $this->runId,
            'seq' => $this->seq,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            runId: (string) ($data['runId'] ?? ''),
            seq: (int) ($data['seq'] ?? 0),
            payload: (array) ($data['payload'] ?? []),
            v: (int) ($data['v'] ?? self::VERSION),
        );
    }
}
