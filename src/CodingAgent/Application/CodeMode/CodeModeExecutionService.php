<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

/** Native ownership begins only after the real validated handler is entered. */
final readonly class CodeModeExecutionService
{
    public function __construct(private StackToolExecutionContextAccessor $contexts, private ToolBatchStoreInterface $batches, private DeferredToolCompletionRepositoryInterface $deferred, private CodeModeExecutionRepository $executions, private CodeModeResultArtifactStore $artifacts, private RunLockManager $locks, private SerializerInterface $serializer, private ApplicationDbTransactionInterface $transactions)
    {
    }

    public function begin(): ?string
    {
        $context = $this->contexts->current();
        if (null === $context || null === $context->stepId() || 1 !== $this->contexts->depth()) {
            return null;
        }

        return $this->locks->synchronized($context->runId(), function () use ($context): string {
            $call = $this->batches->loadCall($context->runId(), $context->turnNo(), $context->stepId(), $context->toolCallId());
            $reference = $this->batches->loadCallReference($context->runId(), $context->turnNo(), $context->stepId(), $context->toolCallId());
            if (null === $call || null === $reference || 'code_mode' !== $call->toolName
                || $call->runId() !== $context->runId() || $call->turnNo() !== $context->turnNo()
                || $call->stepId() !== $context->stepId() || $call->toolCallId !== $context->toolCallId()) {
                throw new \RuntimeException('Code-mode execution has no matching immutable invocation.');
            }
            $identity = Uuid::v7()->toRfc4122();
            if (null !== $this->executions->invocation($call->runId(), $call->toolCallId)) {
                throw new \RuntimeException('Code-mode invocation already has a native owner.');
            }
            $frozen = $this->artifacts->publish($call);
            if ((array) $frozen !== (array) $reference) {
                throw new \RuntimeException('Code-mode invocation does not match its original reference.');
            }
            $this->transactions->transactional(function () use ($identity, $call, $frozen): void {
                $registered = $this->deferred->registerPending(new DeferredToolCompletionCorrelation($identity, $call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->toolName, $call->args, $call->orderIndex, $call->toolIdempotencyKey, $call->mode, $call->timeoutSeconds, $call->maxParallelism, $call->argSchema, $call->toolsRef));
                if ($registered->deferredId !== $identity) {
                    throw new \RuntimeException('Code-mode invocation already has a different deferred owner.');
                }
                $this->executions->register($identity, $call->runId(), $call->toolCallId, $this->serializer->serialize($frozen, 'json'));
            });

            return $identity;
        });
    }

    public function ready(string $identity): void
    {
        $this->executions->ready($identity);
    }

    public function preserve(string $identity, mixed $value, ?\Throwable $failure): void
    {
        $correlation = $this->deferred->findByDeferredId($identity);
        if (null === $correlation) {
            throw new \RuntimeException('Code-mode native correlation disappeared before settlement.');
        }
        $raw = null !== $failure ? [] : ($value instanceof CodeModeExecutionResult ? ['code_mode_value' => $value->result, 'code_mode_diagnostics' => $value->diagnostics] : ['code_mode_value' => $value, 'code_mode_diagnostics' => []]);
        $error = null === $failure ? null : ['type' => $failure::class, 'cause_type' => null === $failure->getPrevious() ? null : $failure->getPrevious()::class, 'message' => $failure->getMessage(), 'retryable' => $failure instanceof ToolCallException ? $failure->retryable() : false, 'hint' => $failure instanceof ToolCallException ? $failure->hint() : null];
        $result = new ToolCallResult($correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId, $correlation->orderIndex, ['tool_name' => 'code_mode', 'content' => null === $failure ? [] : [['type' => 'text', 'text' => $failure->getMessage()]], 'details' => $raw], null !== $failure, $error);
        $reference = $this->artifacts->publish($result->finalized());
        $this->executions->freezeRaw($identity, $this->serializer->serialize($reference, 'json'));
    }

    public function resultReference(string $json): ToolPayloadReferenceDTO
    {
        return $this->serializer->deserialize($json, ToolPayloadReferenceDTO::class, 'json');
    }

    public function invocation(string $json): ExecuteToolCall
    {
        return $this->artifacts->invocation($this->resultReference($json));
    }

    public function matches(ToolCallResult $result, ExecuteToolCall $call): bool
    {
        return [$result->runId(), $result->turnNo(), $result->stepId(), $result->attempt(), $result->idempotencyKey(), $result->toolCallId, $result->orderIndex, $result->result['tool_name'] ?? null]
            === [$call->runId(), $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex, $call->toolName];
    }

    public function sameInvocation(ExecuteToolCall $call, ?ExecuteToolCall $other): bool
    {
        return null !== $other && $this->serializer->serialize($call, 'json') === $this->serializer->serialize($other, 'json');
    }
}
