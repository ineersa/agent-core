<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService;
use Ineersa\CodingAgent\Application\Message\RepairDeferredChildObligationDTO;
use Ineersa\CodingAgent\Application\Message\RepairDeferredChildrenDTO;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RepairDeferredChildrenHandler implements CoordinationActionValidatorInterface
{
    public function __construct(
        private PreparedTransitionEventStoreInterface $store,
        private DeferredSubagentBatchRepository $deferredBatches,
        private DeferredSubagentBatchRecoveryService $deferredRecovery,
        private ActiveRunContextInterface $registry,
        private OwnerRunInitializationMiddleware $initialization,
        private RunMessageProcessor $processor,
        private SessionRepairServiceInterface $repair,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(RepairDeferredChildrenDTO $action): void
    {
        $this->validate($action);
        $pending = $this->store->verifiedPendingTransition($action->runId);
        if (null === $pending || !array_any($pending->work['actions'] ?? [], static fn (mixed $captured): bool => $captured instanceof RepairDeferredChildrenDTO && serialize($captured) === serialize($action))) {
            throw new \RuntimeException('Deferred child maintenance requires its complete verified owner transition.');
        }

        foreach ($action->obligations as $obligation) {
            $batch = $this->deferredBatches->findByLifecycleId($obligation->lifecycleId);
            // Original parent-turn fence: an old repair must not touch a newer generation.
            if (null === $batch || $batch->parentRunId !== $action->runId || $batch->parentTurnNo !== $obligation->parentTurnNo) {
                continue;
            }

            if (RepairDeferredChildObligationDTO::KIND_INTERRUPT === $obligation->kind) {
                if (null !== $batch->interruptionKind) {
                    $this->deferredRecovery->recover($obligation->lifecycleId);
                }
                continue;
            }

            if (RepairDeferredChildObligationDTO::KIND_SETTLE === $obligation->kind) {
                $this->deferredRecovery->recover($obligation->lifecycleId);
                continue;
            }

            if (RepairDeferredChildObligationDTO::KIND_CANCEL !== $obligation->kind || null === $obligation->childRunId) {
                throw new \RuntimeException('Invalid captured deferred child cancellation.');
            }

            $childCommand = new RepairSession($obligation->childRunId, true, $action->commandId);
            $this->initialization->initializeForOwner($obligation->childRunId, $childCommand);
            $state = $this->registry->requireLoaded($obligation->childRunId);
            if ($state->status->isTerminal() || RunStatus::WaitingHuman === $state->status) {
                continue;
            }
            if (RunStatus::Cancelling !== $state->status) {
                $key = 'repair-cancel-'.$action->commandId.'-'.$obligation->childRunId;
                $this->processor->process('repair', new ApplyCommand($obligation->childRunId, $state->turnNo, $key, 1, $key, CoreCommandKind::Cancel, ['reason' => 'Cancelled by session repair.']));
            }
            if (RunStatus::Cancelling === $this->registry->requireLoaded($obligation->childRunId)->status) {
                $childResult = $this->repair->repair($obligation->childRunId, true, $action->commandId);
                if (null !== $childResult->refusalReason) {
                    throw new \RuntimeException('Captured child cancellation repair was refused: '.$childResult->message);
                }
            }
        }
    }

    public function supports(object $action): bool
    {
        return $action instanceof RepairDeferredChildrenDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof RepairDeferredChildrenDTO || '' === $action->runId || '' === $action->commandId || !array_is_list($action->obligations) || [] === $action->obligations) {
            throw new \RuntimeException('Invalid prepared deferred child maintenance.');
        }
        foreach ($action->obligations as $obligation) {
            if (!$obligation instanceof RepairDeferredChildObligationDTO || '' === $obligation->lifecycleId || $obligation->parentTurnNo < 0
                || !\in_array($obligation->kind, [RepairDeferredChildObligationDTO::KIND_INTERRUPT, RepairDeferredChildObligationDTO::KIND_CANCEL, RepairDeferredChildObligationDTO::KIND_SETTLE], true)) {
                throw new \RuntimeException('Invalid captured deferred child obligation.');
            }
            if (RepairDeferredChildObligationDTO::KIND_CANCEL === $obligation->kind && (null === $obligation->childRunId || '' === $obligation->childRunId)) {
                throw new \RuntimeException('Captured child cancellation requires a child identity.');
            }
            if (RepairDeferredChildObligationDTO::KIND_CANCEL !== $obligation->kind && null !== $obligation->childRunId) {
                throw new \RuntimeException('Non-cancel deferred child obligations cannot carry a child identity.');
            }
        }
    }
}
