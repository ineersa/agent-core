<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/**
 * Builder for RunState value objects in tests.
 *
 * Defaults: runId="run-test", status=Queued, version=0, turnNo=0, lastSeq=0,
 * isStreaming=false, streamingMessage=null, pendingToolCalls=[],
 * errorMessage=null, messages=[], activeStepId=null, retryableFailure=false.
 *
 * @phpstan-type StreamingMessage array<string, mixed>|null
 * @phpstan-type PendingToolCalls array<string, bool>
 * @phpstan-type MessagesList list<AgentMessage>
 */
final class RunStateBuilder
{
    private string $runId = 'run-test';
    private RunStatus $status = RunStatus::Queued;
    private int $version = 0;
    private int $turnNo = 0;
    private int $lastSeq = 0;
    private bool $isStreaming = false;

    /** @var StreamingMessage */
    private ?array $streamingMessage = null;

    /** @var PendingToolCalls */
    private array $pendingToolCalls = [];

    private ?string $errorMessage = null;

    /** @var MessagesList */
    private array $messages = [];

    private ?string $activeStepId = null;
    private bool $retryableFailure = false;

    private function __construct(string $runId, RunStatus $status)
    {
        $this->runId = $runId;
        $this->status = $status;
    }

    /**
     * Create a builder pre-configured with Queued status.
     */
    public static function queued(string $runId = 'run-test'): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }

    /**
     * Create a builder pre-configured with Running status.
     */
    public static function running(string $runId = 'run-test'): self
    {
        return new self(runId: $runId, status: RunStatus::Running);
    }

    /**
     * Create a builder with defaults (Queued status).
     */
    public static function create(string $runId = 'run-test'): self
    {
        return new self(runId: $runId, status: RunStatus::Queued);
    }

    public function withRunId(string $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function withStatus(RunStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function withTurnNo(int $turnNo): self
    {
        $this->turnNo = $turnNo;

        return $this;
    }

    public function withLastSeq(int $lastSeq): self
    {
        $this->lastSeq = $lastSeq;

        return $this;
    }

    public function withIsStreaming(bool $isStreaming): self
    {
        $this->isStreaming = $isStreaming;

        return $this;
    }

    /**
     * @param StreamingMessage $streamingMessage
     */
    public function withStreamingMessage(?array $streamingMessage): self
    {
        $this->streamingMessage = $streamingMessage;

        return $this;
    }

    /**
     * @param PendingToolCalls $pendingToolCalls
     */
    public function withPendingToolCalls(array $pendingToolCalls): self
    {
        $this->pendingToolCalls = $pendingToolCalls;

        return $this;
    }

    public function withErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    /**
     * @param MessagesList $messages
     */
    public function withMessages(array $messages): self
    {
        $this->messages = $messages;

        return $this;
    }

    public function withAppendMessage(AgentMessage $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    public function withActiveStepId(?string $activeStepId): self
    {
        $this->activeStepId = $activeStepId;

        return $this;
    }

    public function withRetryableFailure(bool $retryableFailure): self
    {
        $this->retryableFailure = $retryableFailure;

        return $this;
    }

    public function build(): RunState
    {
        return new RunState(
            runId: $this->runId,
            status: $this->status,
            version: $this->version,
            turnNo: $this->turnNo,
            lastSeq: $this->lastSeq,
            isStreaming: $this->isStreaming,
            streamingMessage: $this->streamingMessage,
            pendingToolCalls: $this->pendingToolCalls,
            errorMessage: $this->errorMessage,
            messages: $this->messages,
            activeStepId: $this->activeStepId,
            retryableFailure: $this->retryableFailure,
        );
    }
}
