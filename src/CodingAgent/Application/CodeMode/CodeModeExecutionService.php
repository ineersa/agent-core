<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

/** Native ownership begins only after the real validated handler is entered. */
final readonly class CodeModeExecutionService
{
    public function __construct(
        private StackToolExecutionContextAccessor $contexts,
        private ToolBatchStoreInterface $batches,
        private ToolPayloadStoreInterface $payloads,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private CodeModeExecutionRepository $executions,
        private CodeModeResultArtifactStore $artifacts,
        private RunLockManager $locks,
        private SerializerInterface $serializer,
        private ApplicationDbTransactionInterface $transactions,
        private DeferredToolInvocationFence $fence,
        private RunHistorySourceProviderInterface $histories,
        private ToolBatchRunStoragePathsInterface $paths,
    ) {
    }

    public function begin(): ?string
    {
        $context = $this->contexts->current();
        if (null === $context || null === $context->stepId() || 1 !== $this->contexts->depth()) {
            return null;
        }

        // Storage admission precedes SQLite reads and the registration transaction.
        // Deletion takes the same order; nested correlation publication reuses it.
        return $this->locks->synchronized($context->runId(), fn (): string => $this->paths->withStorageWriteLock($this->paths->resolveToolBatchesDirectory($context->runId()), function () use ($context): string {
            $call = $this->batches->loadCall($context->runId(), $context->turnNo(), $context->stepId(), $context->toolCallId());
            $reference = $this->batches->loadCallReference($context->runId(), $context->turnNo(), $context->stepId(), $context->toolCallId());
            $stored = null === $reference ? null : $this->payloads->read($reference);
            if (null === $call || null === $reference || 'code_mode' !== $call->toolName || !$stored instanceof ExecuteToolCall
                || !$this->fence->sameInvocation($call, $stored)
                || $call->runId() !== $context->runId() || $call->turnNo() !== $context->turnNo()
                || $call->stepId() !== $context->stepId() || $call->toolCallId !== $context->toolCallId()) {
                throw new \RuntimeException('Code-mode execution has no matching immutable invocation.');
            }
            $source = $this->histories->historySource($call->runId());
            $current = $source->log->pendingToolInvocation($source->path, $call->runId(), $call->toolCallId);
            if (null === $current || $current->turnNo !== $call->turnNo() || $current->stepId !== $call->stepId()
                || $context->cancellationToken()->isCancellationRequested()) {
                throw new ToolCallException('Code-mode invocation no longer belongs to the current execution boundary.', retryable: false);
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
                $retained = $this->deferred->findInvocationReferenceByDeferredId($identity);
                if (null === $retained || (array) $retained !== (array) $frozen) {
                    throw new \RuntimeException('Code-mode deferred registration did not retain the exact invocation reference.');
                }
                $this->executions->register($identity, $call->runId(), $call->toolCallId);
            });

            return $identity;
        }));
    }

    public function ready(string $identity): void
    {
        $this->executions->ready($identity);
    }

    public function retainDiagnostic(string $identity, string $source): string
    {
        $metadata = $this->deferred->findMetadataByDeferredId($identity)
            ?? throw new \RuntimeException('Code-mode diagnostic has no retained owner.');

        return $this->artifacts->retainDiagnostic($metadata->reference->runId, $metadata->reference->toolCallId, $source);
    }

    public function cancellationToken(string $identity, CancellationTokenInterface $parent): CancellationTokenInterface
    {
        return new CodeModeCancellationToken($this->executions, $identity, $parent);
    }

    public function preserve(string $identity, mixed $value, ?\Throwable $failure): void
    {
        if (null === $this->deferred->findMetadataByDeferredId($identity)) {
            // Permanent deletion removed ownership. The old host may clean its
            // workspace, but must not reconstruct retained session artifacts.
            return;
        }
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

    public function matches(ToolCallResult $result, ExecuteToolCall $call): bool
    {
        return $this->fence->matchesResult($result, $call);
    }

    public function sameInvocation(ExecuteToolCall $call, ?ExecuteToolCall $other): bool
    {
        return $this->fence->sameInvocation($call, $other);
    }
}
