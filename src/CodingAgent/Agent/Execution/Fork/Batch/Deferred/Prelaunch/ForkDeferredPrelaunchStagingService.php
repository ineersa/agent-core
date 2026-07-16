<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Compaction\CompactionSkipReasonEnum;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fork-local isolated session + canonical AgentRunner::compact pre-launch phase.
 *
 * Parent canonical files are never written. Compaction policy is owned by the shared /compact pipeline.
 */
final readonly class ForkDeferredPrelaunchStagingService
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private ForkSessionCopyService $sessionCopyService,
        private ForkSnapshotSanitizer $snapshotSanitizer,
        private RunStoreInterface $runStore,
        private AgentRunnerInterface $agentRunner,
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
        private EventFactory $eventFactory,
        private CommittedRunEventAppender $committedEventAppender,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<AgentMessage> $parentMessages
     */
    public function beginOrResume(
        string $parentRunId,
        string $parentToolCallId,
        string $batchLifecycleId,
        array $parentMessages,
    ): void {
        $row = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
        if (null === $row) {
            throw new \RuntimeException('Deferred fork batch row missing for pre-launch staging.');
        }

        $forkLocalRunId = $row->forkLocalRunId;
        if (null === $forkLocalRunId || '' === $forkLocalRunId) {
            $forkLocalRunId = $this->sessionStore->createSession('Fork pre-launch compaction copy');
            $this->sessionCopyService->copyParentSessionToForkLocal($parentRunId, $forkLocalRunId);
            $sanitized = $this->snapshotSanitizer->sanitize($parentMessages);
            $this->rewriteForkLocalMessages($forkLocalRunId, $sanitized);
            $this->batchRepository->applyForkPrelaunchStaging(
                $parentRunId,
                $parentToolCallId,
                $forkLocalRunId,
                ForkDeferredPrelaunchPhaseEnum::AwaitingCompaction,
            );
            $row = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
        }

        $phase = ForkDeferredPrelaunchPhaseEnum::tryFrom($row->prelaunchPhase ?? '')
            ?? ForkDeferredPrelaunchPhaseEnum::AwaitingCompaction;

        if (ForkDeferredPrelaunchPhaseEnum::ReadyForChildLaunch === $phase) {
            return;
        }

        if (ForkDeferredPrelaunchPhaseEnum::AwaitingCompaction === $phase) {
            $this->agentRunner->compact($forkLocalRunId);
            $this->batchRepository->applyForkPrelaunchPhase(
                $parentRunId,
                $parentToolCallId,
                ForkDeferredPrelaunchPhaseEnum::CompactionDispatched,
            );
            throw new ForkDeferredPrelaunchPendingException();
        }

        if (ForkDeferredPrelaunchPhaseEnum::CompactionDispatched === $phase) {
            throw new ForkDeferredPrelaunchPendingException();
        }

        if (ForkDeferredPrelaunchPhaseEnum::Failed === $phase) {
            throw new \RuntimeException('Fork pre-launch compaction failed.');
        }
    }

    public function findForkLocalRunId(string $parentRunId, string $parentToolCallId): ?string
    {
        $row = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);

        return $row?->forkLocalRunId;
    }

    /**
     * @param array<string, mixed> $terminalPayload
     */
    public function handleForkLocalCompactionTerminal(string $forkLocalRunId, string $eventType, array $terminalPayload = []): void
    {
        $batch = $this->batchRepository->findByForkLocalRunId($forkLocalRunId);
        if (null === $batch) {
            return;
        }

        if (ForkDeferredPrelaunchPhaseEnum::CompactionDispatched->value !== (string) $batch->prelaunchPhase) {
            return;
        }

        if (RunEventTypeEnum::ContextCompacted->value !== $eventType
            && RunEventTypeEnum::ContextCompactionFailed->value !== $eventType) {
            return;
        }

        if ($this->isStructuralCompactionNoOp($eventType, $terminalPayload)) {
            $this->persistForkLocalSanitizedSnapshot($forkLocalRunId);
        }

        try {
            $this->commandBus->dispatch(new ContinueForkDeferredPrelaunchMessage(
                batchLifecycleId: $batch->lifecycleId,
                forkLocalRunId: $forkLocalRunId,
                terminalEventType: $eventType,
                terminalPayload: $terminalPayload,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('fork_deferred_prelaunch.continue_dispatch_failed', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'fork_local_run_id' => $forkLocalRunId,
                'component' => 'agent.execution.fork',
                'event_type' => 'fork_deferred_prelaunch.continue_dispatch_failed',
                'exception_class' => $e::class,
            ]);

            throw $e;
        }

        $this->batchRepository->applyForkPrelaunchPhase(
            $batch->parentRunId,
            $batch->parentToolCallId,
            ForkDeferredPrelaunchPhaseEnum::ReadyForChildLaunch,
        );
    }

    /**
     * @param array<string, mixed> $terminalPayload
     */
    private function isStructuralCompactionNoOp(string $eventType, array $terminalPayload): bool
    {
        if (RunEventTypeEnum::ContextCompactionFailed->value !== $eventType) {
            return false;
        }

        if (true === ($terminalPayload['messages_replaced'] ?? null)) {
            return false;
        }

        $reason = $terminalPayload['reason'] ?? null;
        if (!\is_string($reason)) {
            return false;
        }

        return null !== CompactionSkipReasonEnum::tryFrom($reason);
    }

    /**
     * Durable fork-local checkpoint: sanitized provider-valid messages survive canonical replay.
     *
     * @param list<AgentMessage> $messages
     */
    private function persistForkLocalSanitizedSnapshot(string $forkLocalRunId): void
    {
        $state = $this->runStore->get($forkLocalRunId);
        if (null === $state) {
            throw new \RuntimeException(\sprintf('Fork-local run state missing for "%s".', $forkLocalRunId));
        }

        $sanitized = $this->snapshotSanitizer->sanitize($state->messages);
        $serialized = array_map(static fn (AgentMessage $message): array => $message->toArray(), $sanitized);

        $events = $this->eventFactory->eventsFromSpecs($forkLocalRunId, $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::RunMessagesReplaced->value,
            'payload' => [
                'messages' => $serialized,
                'pending_tool_calls' => [],
                'reason' => 'fork_prelaunch_sanitize',
                'final_status' => RunStatus::Completed->value,
            ],
        ]]);

        $this->committedEventAppender->appendMany($events);

        $state = $this->runStore->get($forkLocalRunId);
        if (null === $state) {
            throw new \RuntimeException(\sprintf('Fork-local run state missing after checkpoint for "%s".', $forkLocalRunId));
        }

        $next = new RunState(
            runId: $forkLocalRunId,
            status: RunStatus::Completed,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $sanitized,
            activeStepId: null,
            retryableFailure: false,
        );

        if (!$this->runStore->compareAndSwap($next, $state->version)) {
            throw new \RuntimeException(\sprintf('Failed to persist fork-local sanitized checkpoint for "%s".', $forkLocalRunId));
        }
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function rewriteForkLocalMessages(string $forkLocalRunId, array $messages): void
    {
        $state = $this->runStore->get($forkLocalRunId);
        if (null === $state) {
            throw new \RuntimeException(\sprintf('Fork-local run state missing for "%s".', $forkLocalRunId));
        }

        $next = new RunState(
            runId: $forkLocalRunId,
            status: RunStatus::Completed,
            version: $state->version,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $messages,
            activeStepId: null,
            retryableFailure: false,
        );

        if (!$this->runStore->compareAndSwap($next, $state->version)) {
            throw new \RuntimeException(\sprintf('Failed to rewrite fork-local messages for "%s".', $forkLocalRunId));
        }
    }
}
