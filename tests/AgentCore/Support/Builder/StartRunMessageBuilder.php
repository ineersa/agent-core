<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;

/**
 * Builder for StartRun messages in tests.
 *
 * Defaults: runId="run-test", turnNo=0, stepId="start-step-1", attempt=1,
 * deterministic idempotency key, empty StartRunPayload.
 *
 * @phpstan-type MessagesList list<AgentMessage>
 */
final class StartRunMessageBuilder
{
    private string $runId = 'run-test';
    private int $turnNo = 0;
    private string $stepId = 'start-step-1';
    private int $attempt = 1;
    private ?string $idempotencyKey = null;

    /** @var MessagesList */
    private array $payloadMessages = [];
    private string $systemPrompt = '';
    private ?RunMetadata $metadata = null;

    public static function create(string $runId = 'run-test'): self
    {
        $builder = new self();
        $builder->runId = $runId;

        return $builder;
    }

    public function withStepId(string $stepId): self
    {
        $this->stepId = $stepId;

        return $this;
    }

    public function withIdempotencyKey(string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    /**
     * @param MessagesList $messages
     */
    public function withPayloadMessages(array $messages): self
    {
        $this->payloadMessages = $messages;

        return $this;
    }

    public function withMetadata(RunMetadata $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function build(): StartRun
    {
        $idempotencyKey = $this->idempotencyKey ?? hash('sha256', \sprintf(
            'start-run|%s|%s|%s|%d',
            $this->runId,
            $this->stepId,
            $this->turnNo,
            $this->attempt,
        ));

        return new StartRun(
            runId: $this->runId,
            turnNo: $this->turnNo,
            stepId: $this->stepId,
            attempt: $this->attempt,
            idempotencyKey: $idempotencyKey,
            payload: new StartRunPayload(
                systemPrompt: $this->systemPrompt,
                messages: $this->payloadMessages,
                metadata: $this->metadata ?? new RunMetadata(model: 'test-model'),
            ),
        );
    }
}
