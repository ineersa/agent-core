<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final class InMemoryToolPayloadStore implements ToolPayloadStoreInterface
{
    /** @var array<string, ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation> */
    private array $messages = [];
    /** @var array<string, ToolPayloadReferenceDTO> */
    private array $references = [];
    /** @var array<string, ToolPayloadReferenceDTO> */
    private array $processed = [];

    public function forRun(string $runId): ToolPayloadStoreInterface
    {
        return $this;
    }

    public function publish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): ToolPayloadReferenceDTO
    {
        $kind = $message instanceof ExecuteToolCall ? 'call' : ($message instanceof ExecuteShellToolCall ? 'shell' : 'result');
        $bytes = serialize($message);
        $name = $message instanceof ExecuteToolCall ? $message->toolName : ($message instanceof ExecuteShellToolCall ? 'bash' : ($message->result['tool_name'] ?? null));
        $reference = new ToolPayloadReferenceDTO($kind, $message->runId(), $message->turnNo(), $message->stepId(), $message->attempt(), $message->idempotencyKey(), $message->toolCallId, $message instanceof ExecuteShellToolCall ? 0 : $message->orderIndex, $name, hash('sha256', $bytes), \strlen($bytes));
        $this->messages[$reference->sha256] = $message;
        $this->references[$reference->sha256] = $reference;

        return $reference;
    }

    public function read(ToolPayloadReferenceDTO $reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation
    {
        return $this->messages[$reference->sha256];
    }

    public function publishCorrelation(DeferredToolCompletionCorrelation $correlation): ToolPayloadReferenceDTO
    {
        $bytes = serialize($correlation);
        $reference = new ToolPayloadReferenceDTO('correlation', $correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId, $correlation->orderIndex, $correlation->toolName, hash('sha256', $bytes), \strlen($bytes));
        $this->messages[$reference->sha256] = $correlation;
        $this->references[$reference->sha256] = $reference;

        return $reference;
    }

    public function readCorrelation(ToolPayloadReferenceDTO $reference): DeferredToolCompletionCorrelation
    {
        $message = $this->read($reference);
        if (!$message instanceof DeferredToolCompletionCorrelation) {
            throw new \LogicException('Not a correlation.');
        }

        return $message;
    }

    public function retainProcessedResult(ToolPayloadReferenceDTO $invocation, ToolCallResult $result): ToolPayloadReferenceDTO
    {
        return $this->processed[$invocation->sha256] ??= $this->publish($result);
    }

    public function findProcessedResultReference(ToolPayloadReferenceDTO $invocation): ?ToolPayloadReferenceDTO
    {
        return $this->processed[$invocation->sha256] ?? null;
    }

    public function deleteCall(string $runId, string $toolCallId): void
    {
        foreach ($this->references as $hash => $reference) {
            if ($reference->runId === $runId && $reference->toolCallId === $toolCallId) {
                unset($this->references[$hash], $this->messages[$hash], $this->processed[$hash]);
            }
        }
    }

    public function deleteAllForRun(string $runId): void
    {
        foreach ($this->references as $reference) {
            if ($reference->runId === $runId) {
                $this->deleteCall($runId, $reference->toolCallId);
            }
        }
    }
}
