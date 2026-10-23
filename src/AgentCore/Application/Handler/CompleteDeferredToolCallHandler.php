<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\DeferredAcceptedResultReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredAcknowledgedCompletionRouterInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CompleteDeferredToolCallHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferredRepository,
        private MessageBusInterface $commandBus,
        private RunLockManager $locks,
        private ToolBatchStoreInterface $batches,
        private ToolPayloadStoreInterface $payloads,
        private DeferredToolInvocationFence $fence,
        private LoggerInterface $logger,
        private ?DeferredAcknowledgedCompletionRouterInterface $acknowledgedRouter,
        private ?DeferredAcceptedResultReaderInterface $acceptedResults,
        private EventDispatcherInterface $events,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(CompleteDeferredToolCall $message): void
    {
        if ('completed' === $this->deferredRepository->status($message->deferredId)) {
            return;
        }
        $correlation = $this->deferredRepository->findByDeferredId($message->deferredId);
        if (null === $correlation) {
            $this->logger->warning('deferred_tool_completion.unknown_correlation', [
                'deferred_id' => $message->deferredId,
                'component' => 'tool',
                'event_type' => 'deferred_tool_completion.unknown_correlation',
            ]);

            throw new \RuntimeException(\sprintf('Unknown deferred tool completion id "%s".', $message->deferredId));
        }

        RunLogContext::enter([
            'run_id' => $correlation->runId,
            'session_id' => $correlation->runId,
            'component' => 'tool',
            'event_type' => 'deferred_tool_completion.started',
            'deferred_id' => $message->deferredId,
            'tool_call_id' => $correlation->toolCallId,
        ]);

        try {
            if ('completed' === $this->deferredRepository->status($message->deferredId)) {
                return;
            }

            $accepted = $this->deferredRepository->findAcceptedResultReferenceByDeferredId($message->deferredId);
            if (null === $accepted) {
                $invocationReference = $this->deferredRepository->findInvocationReferenceByDeferredId($message->deferredId)
                    ?? throw new \RuntimeException('Pending deferred registration is missing its invocation reference.');
                $call = $this->payloads->read($invocationReference);
                if (!$call instanceof ExecuteToolCall) {
                    throw new \RuntimeException('Deferred invocation reference does not identify an ExecuteToolCall.');
                }
                $toolCallResult = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
                    $correlation,
                    $message->content,
                    $message->details,
                    $message->isError,
                    $message->error,
                );
                if (!$this->fence->matchesResult($toolCallResult, $call)) {
                    throw new \RuntimeException('Deferred completion does not match its frozen invocation.');
                }
                $accepted = $this->deferredRepository->acceptResult(
                    $message->deferredId,
                    $this->payloads->publish($toolCallResult),
                );
            }

            $result = null !== $this->acceptedResults
                ? $this->acceptedResults->read($message->deferredId, $accepted)
                : $this->payloads->read($accepted);
            if (!$result instanceof ToolCallResult) {
                throw new \RuntimeException('Accepted deferred completion is not a tool result.');
            }

            // Failed sends retain the first accepted result for explicit repair.
            // Acknowledged results also wait for durable mailbox admission.
            $acknowledged = null !== $this->deferredRepository->acknowledgedAt($message->deferredId);
            $this->locks->afterRelease($correlation->runId, function () use ($message, $correlation, $result, $acknowledged): void {
                try {
                    if ($acknowledged) {
                        if (null === $this->acknowledgedRouter) {
                            throw new \LogicException('Acknowledged deferred completion requires a CodingAgent router.');
                        }
                        $this->acknowledgedRouter->deliver($message->deferredId, $result);
                    } else {
                        $this->commandBus->dispatch($result);
                    }
                } catch (\Throwable $exception) {
                    $this->logger->warning('deferred_tool_completion.delivery_failed', [
                        'run_id' => $correlation->runId,
                        'session_id' => $correlation->runId,
                        'component' => 'deferred_tool_completion',
                        'event_type' => 'deferred_tool_completion.delivery_failed',
                        'deferred_id' => $message->deferredId,
                        'exception_class' => $exception::class,
                    ]);

                    return;
                }
                // Acknowledged work settles only after durable background-completion
                // receipt admission. Successful enqueue alone is not settlement.
                if (!$acknowledged) {
                    $this->settle($message->deferredId, $correlation->runId, $correlation->turnNo, $correlation->stepId);
                }
            });
        } finally {
            RunLogContext::leave();
        }
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function redrive(RedriveDeferredToolCall $message): void
    {
        $this->locks->synchronized($message->runId(), function () use ($message): void {
            if ('completed' === $this->deferredRepository->status($message->deferredId)) {
                return;
            }
            $metadata = $this->deferredRepository->findMetadataByDeferredId($message->deferredId);
            if (null === $metadata || $metadata->reference->runId !== $message->runId()) {
                throw new \RuntimeException('Deferred repair requires a retained result for the same run.');
            }
            if (null === $this->deferredRepository->findAcceptedResultReferenceByDeferredId($message->deferredId)) {
                $reference = $this->deferredRepository->findInvocationReferenceByDeferredId($message->deferredId)
                    ?? throw new \RuntimeException('Deferred repair lost its invocation reference.');
                $processed = $this->payloads->findProcessedResultReference($reference)
                    ?? throw new \RuntimeException('Deferred repair requires retained processed terminal evidence.');
                $call = $this->payloads->read($reference);
                if (!$call instanceof ExecuteToolCall) {
                    throw new \RuntimeException('Deferred repair lost its immutable invocation.');
                }
                $result = $this->payloads->read($processed);
                if (!$result instanceof ToolCallResult || null !== $result->pendingHumanInput || !$this->fence->matchesResult($result, $call)) {
                    throw new \RuntimeException('Deferred repair requires matching processed terminal evidence.');
                }
                // Reuse native publication validation, not execution or result
                // processors. Generic deferred producers use the same acceptance.
                $publication = new ProcessedToolResultDTO($result, $call);
                $this->events->dispatch($publication);
                if ($publication->handled) {
                    return;
                }
                $this->deferredRepository->acceptResult($message->deferredId, $processed);
            }
            // The accepted-reference check prevents repair from manufacturing a
            // terminal outcome. __invoke reads the first frozen result, not this body.
            $this(new CompleteDeferredToolCall($message->deferredId, []));
        });
    }

    /** Settle admitted or explicitly discarded completions, then retry guarded cleanup. */
    public function settle(string $deferredId, string $runId, int $turnNo, string $stepId): void
    {
        $this->locks->synchronized($runId, function () use ($deferredId, $runId, $turnNo, $stepId): void {
            $this->deferredRepository->markCompleted($deferredId);
            // Synchronous delivery and obsolete destinations can leave a settled
            // batch retained solely by its pending deferred marker.
            $this->batches->delete($runId, $turnNo, $stepId);
        });
    }
}
