<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Command from the client (TUI/process) to the agent runtime.
 *
 * In JSONL mode, this is serialized line-by-line to stdout of the headless process.
 * Each command carries a unique client-assigned id for ACK correlation.
 */
final readonly class RuntimeCommand
{
    private const int VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $runId = null,
        public array $payload = [],
        public int $v = self::VERSION,
    ) {
    }

    /**
     * @return array{v: int, id: string, type: string, runId: string|null, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'id' => $this->id,
            'type' => $this->type,
            'runId' => $this->runId,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            runId: isset($data['runId']) ? (string) $data['runId'] : null,
            payload: (array) ($data['payload'] ?? []),
            v: (int) ($data['v'] ?? self::VERSION),
        );
    }
}
