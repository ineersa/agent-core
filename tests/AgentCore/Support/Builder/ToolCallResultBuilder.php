<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * Builder for ToolCallResult messages in tests.
 *
 * Defaults: runId="run-test", turnNo=1, stepId="step-1", attempt=1,
 * deterministic idempotency key, toolCallId="tool-call-1", orderIndex=0,
 * successful result payload ['tool_name' => 'web_search', 'content' => [['type' => 'text', 'text' => 'ok']]],
 * isError=false, error=null.
 *
 * @phpstan-type ResultPayload array<string, mixed>|string|int|float|bool|null
 * @phpstan-type ErrorPayload array<string, mixed>|null
 */
final class ToolCallResultBuilder
{
    private string $runId = 'run-test';
    private int $turnNo = 1;
    private string $stepId = 'step-1';
    private int $attempt = 1;
    private ?string $idempotencyKey = null;
    private string $toolCallId = 'tool-call-1';
    private int $orderIndex = 0;

    /** @var ResultPayload */
    private mixed $result = ['tool_name' => 'web_search', 'content' => [['type' => 'text', 'text' => 'ok']]];

    private bool $isError = false;

    /** @var ErrorPayload */
    private ?array $error = null;

    /**
     * Create a builder with success defaults.
     */
    public static function success(string $runId = 'run-test'): self
    {
        return (new self())->withRunId($runId)->withIsError(false);
    }

    /**
     * Create a builder pre-configured for an error result.
     */
    public static function error(string $runId = 'run-test', string $message = 'Tool execution failed'): self
    {
        return (new self())->withRunId($runId)
            ->withIsError(true)
            ->withError(['message' => $message])
            ->withResult(null);
    }

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

    public function withToolCallId(string $toolCallId): self
    {
        $this->toolCallId = $toolCallId;

        return $this;
    }

    public function withOrderIndex(int $orderIndex): self
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    /**
     * @param ResultPayload $result
     */
    public function withResult(mixed $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function withIsError(bool $isError): self
    {
        $this->isError = $isError;

        return $this;
    }

    /**
     * @param ErrorPayload $error
     */
    public function withError(?array $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function build(): ToolCallResult
    {
        $idempotencyKey = $this->idempotencyKey ?? hash('sha256', \sprintf(
            'tool-result|%s|%s|%s|%d',
            $this->runId,
            $this->toolCallId,
            $this->stepId,
            $this->attempt,
        ));

        return new ToolCallResult(
            runId: $this->runId,
            turnNo: $this->turnNo,
            stepId: $this->stepId,
            attempt: $this->attempt,
            idempotencyKey: $idempotencyKey,
            toolCallId: $this->toolCallId,
            orderIndex: $this->orderIndex,
            result: $this->result,
            isError: $this->isError,
            error: $this->error,
        );
    }
}
