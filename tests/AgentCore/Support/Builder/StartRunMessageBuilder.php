<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;

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
     * @param MessagesList $messages
     */
    public function withPayloadMessages(array $messages): self
    {
        $this->payloadMessages = $messages;

        return $this;
    }

    public function withSystemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;

        return $this;
    }

    /**
     * Convenience: add a single user text message to the payload.
     */
    public function withUserTextMessage(string $text): self
    {
        $this->payloadMessages[] = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $text]],
        );

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
            ),
        );
    }
}
