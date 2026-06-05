<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;

/**
 * Builder for AdvanceRun messages in tests.
 *
 * Defaults: runId="run-test", turnNo=0, stepId="turn-1-step", attempt=1,
 * deterministic idempotency key, empty payload.
 *
 * @phpstan-type Payload array<string, mixed>
 */
final class AdvanceRunMessageBuilder
{
    private string $runId = 'run-test';
    private int $turnNo = 0;
    private string $stepId = 'turn-1-step';
    private int $attempt = 1;
    private ?string $idempotencyKey = null;

    /** @var Payload */
    private array $payload = [];

    public static function create(string $runId = 'run-test'): self
    {
        return (new self())->withRunId($runId);
    }

    public function withRunId(string $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function withTurnNo(int $turnNo): self
    {
        $this->turnNo = $turnNo;

        return $this;
    }

    public function withStepId(string $stepId): self
    {
        $this->stepId = $stepId;

        return $this;
    }

    public function withAttempt(int $attempt): self
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function withIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    /**
     * @param Payload $payload
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function build(): AdvanceRun
    {
        $idempotencyKey = $this->idempotencyKey ?? hash('sha256', \sprintf(
            'advance-run|%s|%s|%s|%d',
            $this->runId,
            $this->stepId,
            $this->turnNo,
            $this->attempt,
        ));

        return new AdvanceRun(
            runId: $this->runId,
            turnNo: $this->turnNo,
            stepId: $this->stepId,
            attempt: $this->attempt,
            idempotencyKey: $idempotencyKey,
            payload: $this->payload,
        );
    }
}
