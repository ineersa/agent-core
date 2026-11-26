<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ApplyCommand extends AbstractAgentBusMessage
{
    /**
     * Initializes the command with run, turn, step, attempt, and idempotency context.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $kind,
        public array $payload = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // Native run-control deliveries can still carry an obsolete options field.
        $prefix = "\0".AbstractAgentBusMessage::class."\0";
        // Native deserialization allocates a fresh instance with uninitialized readonly fields.
        parent::__construct(
            $data[$prefix.'runId'],
            $data[$prefix.'turnNo'],
            $data[$prefix.'stepId'],
            $data[$prefix.'attempt'],
            $data[$prefix.'idempotencyKey'],
        );
        $this->kind = $data['kind'];
        $this->payload = $data['payload'];
    }
}
