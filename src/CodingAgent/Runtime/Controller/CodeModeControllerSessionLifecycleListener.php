<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Controller ownership loss fences old hosts without deleting recoverable evidence. */
final readonly class CodeModeControllerSessionLifecycleListener
{
    public function __construct(
        private OwnedRunIdsProvider $ownership,
        private CodeModeExecutionRepository $executions,
        private RunLockManager $locks,
        private ApplicationDbTransactionInterface $transactions,
        private ToolBatchRunStoragePathsInterface $paths,
        private CompleteDeferredToolCallHandler $completions,
        private DeferredToolCompletionRepositoryInterface $deferred,
    ) {
    }

    #[AsEventListener]
    public function starting(ControllerSessionStartingEvent $event): void
    {
        $this->interrupt($event->sessionId, CodeModeInterruptionReasonEnum::ControllerRestarted);
    }

    #[AsEventListener(priority: 512)]
    public function shutdown(ControllerSessionShutdownEvent $event): void
    {
        if (!$event->permanentDeletion) {
            $this->interrupt($event->sessionId, CodeModeInterruptionReasonEnum::ControllerShutdown);
        }
    }

    private function interrupt(string $owner, CodeModeInterruptionReasonEnum $reason): void
    {
        foreach ($this->ownership->forOwner($owner) as $runId) {
            $this->locks->synchronized($runId, function () use ($runId, $reason): void {
                $identities = $this->executions->identitiesForRun($runId);
                if ([] === $identities) {
                    return;
                }
                $cleanup = [];
                // Storage lock still guards publication races during recovery.
                // Obligation parking and retirement stay with shared deferred ownership.
                $this->paths->withStorageWriteLock($this->paths->resolveToolBatchesDirectory($runId), function () use ($identities, $reason, &$cleanup): void {
                    foreach ($identities as $identity) {
                        $row = $this->executions->byIdentity($identity)
                            ?? throw new \RuntimeException('Controller recovery lost its native host facts.');
                        $currentReason = \is_string($row['interruption_reason'] ?? null) && '' !== $row['interruption_reason']
                            ? CodeModeInterruptionReasonEnum::from($row['interruption_reason'])
                            : null;
                        $abandoned = \in_array($currentReason, [
                            CodeModeInterruptionReasonEnum::Cancelled,
                            CodeModeInterruptionReasonEnum::HistoryDiscarded,
                        ], true);
                        $message = $abandoned ? null : 'Code-mode execution was interrupted by controller ownership loss. No processed terminal result was retained; external effects may already have occurred. Execution was not retried.';
                        $acted = false;
                        $metadata = $abandoned ? $this->deferred->findMetadataByDeferredId($identity) : null;
                        $this->transactions->transactional(function () use ($identity, $reason, $abandoned, $message, &$acted): void {
                            $acted = $this->completions->parkForControllerLoss($identity, $abandoned, $message);
                            if ($acted && !$abandoned) {
                                $this->executions->interrupt($identity, $reason);
                            }
                        });
                        if ($acted && $abandoned && null !== $metadata) {
                            $cleanup[$identity] = $metadata->reference;
                        }
                    }
                });
                // Immutable cleanup follows metadata commit and can retry on duplicates.
                foreach ($cleanup as $identity => $reference) {
                    $this->completions->settle($identity, $reference->runId, $reference->turnNo, $reference->stepId);
                }
            });
        }
    }
}
