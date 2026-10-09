<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Application\Message\RepairDeferredChildObligationDTO;
use Ineersa\CodingAgent\Application\Message\RepairDeferredChildrenDTO;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
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
        private \Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery $recovery,
        private DeferredSubagentBatchRepository $deferredBatches,
        private \Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer $bootstrap,
        private \Ineersa\AgentCore\Application\Handler\RunLockManager $locks,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function attach(\Ineersa\CodingAgent\Application\Message\AttachRun $command): void
    {
        $this->locks->synchronized($command->runId, function () use ($command): void {
            try {
                $state = $this->bootstrap->prepare($command->runId);
                if (RunStatus::WaitingHuman === $state->status || [] !== $state->pendingHumanInputRequests) {
                    $step = 'attach-cancel-'.$command->commandId;
                    $this->processor->process('attach', new \Ineersa\AgentCore\Domain\Message\ApplyCommand($command->runId, $state->turnNo, $step, 1, $step, 'cancel', ['reason' => 'Outstanding human questions cancelled on session attach.']));
                }
                // Do not retain the old context beside the refreshed owner state.
                unset($state);
                $this->sessions->resetReasoningBaseline($command->runId);
                $this->processor->process('attach', new \Ineersa\AgentCore\Domain\Message\RefreshRunContext($command->runId, $command->messages));
                $descriptor = $this->bootstrap->seal();
                // A slow controller must never hold the owner transition lock while
                // receiving the token. The body remains in the private bounded spool.
                $this->locks->afterRelease($command->runId, fn () => $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::BootstrapAvailable->value, runId: $command->runId, seq: 0, payload: $descriptor->toArray() + ['request_id' => $command->commandId])));
            } finally {
                $this->bootstrap->release();
            }
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function select(SelectHistoryPrompt $command): RuntimeEvent
    {
        try {
            $this->initialization->initializeForOwner($command->runId, $command);
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
                $plan = $this->prepareDeferredChildMaintenance($command);
                if ($plan instanceof RepairResult) {
                    $result = $plan;
                } else {
                    $actions = [];
                    if ($command->apply && [] !== $plan['obligations']) {
                        $actions[] = new RepairDeferredChildrenDTO($command->runId, $command->commandId, $plan['obligations']);
                    }
                    $result = $this->repair->repair($command->runId, $command->apply, $command->commandId, $actions);
                    if (null === $result->refusalReason) {
                        $result = $this->mergeDeferredChildPlan($result, $plan);
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'failed', 'exception_class' => $exception::class]));
            throw $exception;
        }
        $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: RepairResultNormalizer::toArray($result) + ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'completed']));

        return $result;
    }

    /**
     * @return array{obligations: list<RepairDeferredChildObligationDTO>, message: ?string, activeOperationsRedriven: int}|RepairResult
     */
    private function prepareDeferredChildMaintenance(RepairSession $command): array|RepairResult
    {
        $parent = $this->registry->requireLoaded($command->runId);
        if (RunStatus::Running !== $parent->status) {
            return ['obligations' => [], 'message' => null, 'activeOperationsRedriven' => 0];
        }

        $obligations = [];
        $redriven = 0;
        $message = null;
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
                $obligations[] = new RepairDeferredChildObligationDTO(RepairDeferredChildObligationDTO::KIND_INTERRUPT, $batch->lifecycleId, $batch->parentTurnNo);
                if ($command->apply) {
                    ++$redriven;
                    $message = 'Deferred child interruption redispatch requested.';
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
                // Preview every child before capturing cancellation for this batch.
                $childCommand = new RepairSession($child->childRunId, $command->apply, $command->commandId);
                $this->initialization->initializeForOwner($child->childRunId, $childCommand);
                $state = $this->registry->requireLoaded($child->childRunId);
                if ($state->isStreaming) {
                    return new RepairResult(
                        repairableStaleCancellationDetected: false,
                        staleCancellationRepaired: false,
                        message: 'Session repair refused: active streaming detected.',
                        refusalReason: SessionRepairRefusalReasonEnum::ActiveStreaming,
                    );
                }
                if ($state->status->isTerminal()) {
                    continue;
                }
                $allTerminal = false;
                if (RunStatus::WaitingHuman === $state->status) {
                    continue;
                }
                // Parent maintenance cancels unfinished children rather than
                // repeating their external operations to obtain another outcome.
                $unfinished[] = $child->childRunId;
            }
            if ([] !== $unfinished) {
                $message = $command->apply ? 'Deferred children cancelled by session repair.' : 'Deferred children would be cancelled by session repair.';
                foreach ($unfinished as $childRunId) {
                    $obligations[] = new RepairDeferredChildObligationDTO(RepairDeferredChildObligationDTO::KIND_CANCEL, $batch->lifecycleId, $batch->parentTurnNo, $childRunId);
                }
            }
            if ($command->apply) {
                // Reconcile terminal children whose final observation was lost
                // at shutdown or during repair, then settle the existing parent
                // tool call. Completed siblings retain their natural outcomes.
                $obligations[] = new RepairDeferredChildObligationDTO(RepairDeferredChildObligationDTO::KIND_SETTLE, $batch->lifecycleId, $batch->parentTurnNo);
                if ($allTerminal) {
                    ++$redriven;
                    $message = 'Deferred child result delivery requested.';
                }
            }
        }

        return ['obligations' => $obligations, 'message' => $message, 'activeOperationsRedriven' => $redriven];
    }

    /**
     * @param array{obligations: list<RepairDeferredChildObligationDTO>, message: ?string, activeOperationsRedriven: int} $plan
     */
    private function mergeDeferredChildPlan(RepairResult $result, array $plan): RepairResult
    {
        if (null === $plan['message'] && 0 === $plan['activeOperationsRedriven']) {
            return $result;
        }
        // Required local obligations ran from the verified journal. Transport
        // sends may still await lock release; do not report queue acceptance here.
        $message = $plan['message'] ?? $result->message;
        $redriven = $result->activeOperationsRedriven + $plan['activeOperationsRedriven'];

        return new RepairResult($result->repairableStaleCancellationDetected, $result->staleCancellationRepaired, $message, $result->refusalReason, $redriven);
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
