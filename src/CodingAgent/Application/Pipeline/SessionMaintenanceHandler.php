<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RunHistoryPositionChangedEventFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Session\Repair\RepairResultNormalizer;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SessionMaintenanceHandler
{
    public function __construct(
        private HistorySelectionServiceInterface $history,
        private SessionRepairServiceInterface $repair,
        private InMemoryRuntimeEventSink $sink,
        private StdoutRuntimeEventSink $stdoutSink,
        #[Autowire('%env(bool:HATFIELD_CONSUMER_STDOUT_EVENTS)%')]
        private bool $consumerStdoutEvents,
        private \Psr\Log\LoggerInterface $logger,
        private \Ineersa\AgentCore\Contract\ActiveRunContextInterface $registry,
        private \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor $processor,
        private \Ineersa\CodingAgent\Session\HatfieldSessionStore $sessions,
        private \Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware $initialization,
        private \Ineersa\AgentCore\Application\Pipeline\RunCommit $runCommit,
        private \Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery $recovery,
        private DeferredSubagentBatchRepository $deferredBatches,
        private DeferredSubagentBatchRecoveryService $deferredRecovery,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function attach(\Ineersa\CodingAgent\Application\Message\AttachRun $command): void
    {
        $source = SourceAcceptance::actionIdentity($command::class, $command->runId, $command->commandId);
        if ($this->runCommit->sourceIdentityAlreadyAccepted($source)) {
            return;
        }
        $state = $this->registry->requireLoaded($command->runId);
        if (RunStatus::WaitingHuman === $state->status || [] !== $state->pendingHumanInputRequests) {
            $step = 'attach-cancel-'.$command->commandId;
            $this->processor->process('attach', new ApplyCommand($command->runId, $state->turnNo, $step, 1, $step, 'cancel', ['reason' => 'Outstanding human questions cancelled on session attach.']), SourceAcceptance::actionIdentity($command::class, $command->runId, $command->commandId, 'cancel'));
        }
        $this->sessions->resetReasoningBaseline($command->runId);
        $this->processor->process('attach', new \Ineersa\AgentCore\Domain\Message\RefreshRunContext($command->runId, $command->messages), $source);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function select(SelectHistoryPrompt $command): RuntimeEvent
    {
        try {
            $this->initialization->initializeForOwner($command->runId, $command);
            if ($this->runCommit->sourceIdentityAlreadyAccepted(SourceAcceptance::actionIdentity($command::class, $command->runId, $command->commandId))) {
                $event = new RuntimeEvent(RuntimeEventTypeEnum::CommandAck->value, $command->runId, 0, ['commandId' => $command->commandId, 'commandType' => 'select_history_turn', 'status' => 'accepted']);
                $this->emit($event);

                return $event;
            }
            $result = $this->history->selectPrompt($command->runId, $command->turnNo, $command->commandId);
            $event = RunHistoryPositionChangedEventFactory::create($command->runId, $result['positionEventSeq'], $result['rebuiltState']->turnNo, $result['selectedPromptTurnNo'], $result['editorPromptText']);
        } catch (\Throwable $exception) {
            $this->logger->warning('history.selection.failed', ['run_id' => $command->runId, 'component' => 'session.maintenance', 'event_type' => 'history.selection.failed', 'exception_class' => $exception::class]);
            $event = new RuntimeEvent(type: RuntimeEventTypeEnum::ProtocolError->value, runId: $command->runId, seq: 0, payload: ['error' => 'History select failed.', 'exception_class' => $exception::class]);
        }
        $this->emit($event);

        return $event;
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function repair(RepairSession $command): RepairResult
    {
        try {
            // Reconcile a previous stage before integrity checks, without
            // admitting empty or corrupt history into the owner registry.
            $this->recovery->recover($command->runId);
            $result = $this->repair->integrityRefusal($command->runId);
            if (null === $result) {
                $this->initialization->initializeForOwner($command->runId, $command);
                $result = $this->repair->repair($command->runId, $command->apply, $command->commandId);
                if (null === $result->refusalReason) {
                    $result = $this->repairDeferredChildren($command, $result);
                }
            }
        } catch (\Throwable $exception) {
            $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'failed', 'exception_class' => $exception::class]));
            throw $exception;
        }
        $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: RepairResultNormalizer::toArray($result) + ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'completed']));

        return $result;
    }

    private function repairDeferredChildren(RepairSession $command, RepairResult $result): RepairResult
    {
        $parent = $this->registry->requireLoaded($command->runId);
        if (RunStatus::Running !== $parent->status) {
            return $result;
        }
        $redriven = $result->activeOperationsRedriven;
        $detected = $result->repairableStaleCancellationDetected;
        $repaired = $result->staleCancellationRepaired;
        $message = $result->message;
        foreach (array_keys($parent->pendingToolCalls) as $toolCallId) {
            $batch = $this->deferredBatches->findByParentRunAndToolCall($command->runId, $toolCallId);
            if (null === $batch || $batch->parentTurnNo !== $parent->turnNo || DeferredSubagentBatchLaunchStatusEnum::Launched !== $batch->launchStatus || null !== $batch->terminalCompletionEnqueuedAt) {
                continue;
            }
            // Launch/startup already queued the deadline message. Leave expired
            // work to that timeout owner rather than restarting its execution.
            if (null !== $batch->deadlineAt && $batch->deadlineAt <= \Symfony\Component\Clock\Clock::get()->now()) {
                continue;
            }
            // Cancellation and timeout retain their existing lifecycle owner.
            // Never retry a child whose batch is already being interrupted.
            if (null !== $batch->interruptionKind) {
                if ($command->apply) {
                    $this->deferredRecovery->recover($batch->lifecycleId);
                    ++$redriven;
                    $message = 'Deferred child interruption redriven.';
                }
                continue;
            }
            $allTerminal = true;
            $unfinished = [];
            foreach ($batch->children as $child) {
                if (DeferredSubagentChildLaunchStatusEnum::Launched !== $child->launchStatus) {
                    $allTerminal = false;
                    continue;
                }
                $refusal = $this->repair->integrityRefusal($child->childRunId);
                if (null !== $refusal) {
                    return $refusal;
                }
                // Admit the existing child through the audited cold-owner entry.
                // Preview every child before applying cancellation to this batch.
                $childCommand = new RepairSession($child->childRunId, $command->apply, $command->commandId);
                $this->initialization->initializeForOwner($child->childRunId, $childCommand);
                $state = $this->registry->requireLoaded($child->childRunId);
                if ($state->status->isTerminal()) {
                    continue;
                }
                $allTerminal = false;
                if (RunStatus::WaitingHuman === $state->status) {
                    continue;
                }
                // Parent maintenance cancels unfinished children. Do not preview
                // unauthorized LLM redrive; missing authorization is expected for
                // abandoned child claims and must not block cancellation.
                $unfinished[] = $child->childRunId;
            }
            if ([] !== $unfinished) {
                $message = $command->apply ? 'Deferred children cancelled by session repair.' : 'Deferred children would be cancelled by session repair.';
            }
            if ($command->apply) {
                foreach ($unfinished as $childRunId) {
                    $state = $this->registry->requireLoaded($childRunId);
                    if (RunStatus::Cancelling !== $state->status) {
                        $key = 'repair-cancel-'.$command->commandId.'-'.$childRunId;
                        $this->processor->process('repair', new ApplyCommand($childRunId, $state->turnNo, $key, 1, $key, CoreCommandKind::Cancel, ['reason' => 'Cancelled by session repair.']));
                    }
                    // A claimed request may never deliver its cancellation result.
                    // Existing cancellation repair aborts it durably and fences
                    // late results instead of waiting for or retrying the worker.
                    if (RunStatus::Cancelling === $this->registry->requireLoaded($childRunId)->status) {
                        $childResult = $this->repair->repair($childRunId, true, $command->commandId);
                        if (null !== $childResult->refusalReason) {
                            return $childResult;
                        }
                        $detected = $detected || $childResult->repairableStaleCancellationDetected;
                        $repaired = $repaired || $childResult->staleCancellationRepaired;
                    }
                }
                // Reconcile terminal children whose final observation was lost
                // at shutdown or during repair, then settle the existing parent
                // tool call. Completed siblings retain their natural outcomes.
                $this->deferredRecovery->recover($batch->lifecycleId);
                if ($allTerminal) {
                    ++$redriven;
                    $message = 'Deferred child result delivery redriven.';
                }
            }
        }

        return new RepairResult($detected, $repaired, $message, activeOperationsRedriven: $redriven);
    }

    private function emit(RuntimeEvent $event): void
    {
        if ($this->consumerStdoutEvents) {
            $this->stdoutSink->emit($event);
        } else {
            $this->sink->emit($event);
        }
    }
}
