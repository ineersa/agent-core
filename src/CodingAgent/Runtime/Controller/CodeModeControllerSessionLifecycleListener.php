<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
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
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolPayloadStoreInterface $payloads,
        private CodeModeResultArtifactStore $artifacts,
        private ApplicationDbTransactionInterface $transactions,
        private ToolBatchRunStoragePathsInterface $paths,
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
                if ([] === $this->executions->pendingControllerRecoveryIdentities($runId) && [] === $this->executions->activeIdentities($runId)) {
                    return;
                }
                $this->paths->withStorageWriteLock($this->paths->resolveToolBatchesDirectory($runId), function () use ($runId, $reason): void {
                    foreach ($this->executions->pendingControllerRecoveryIdentities($runId) as $identity) {
                        $this->transactions->transactional(function () use ($identity, $reason): void {
                            $this->deferred->requireContinuation($identity, true);
                            $reference = $this->deferred->findInvocationReferenceByDeferredId($identity)
                                ?? throw new \RuntimeException('Controller recovery lost its retained invocation.');
                            $accepted = $this->deferred->findAcceptedResultReferenceByDeferredId($identity);
                            if (null === $accepted) {
                                $processed = $this->executions->isActive($identity) ? $this->payloads->findProcessedResultReference($reference) : null;
                                if (null !== $processed) {
                                    $result = $this->payloads->read($processed);
                                    if (!$result instanceof ToolCallResult || null !== $result->pendingHumanInput) {
                                        throw new \RuntimeException('Controller recovery requires terminal evidence.');
                                    }
                                } else {
                                    $text = 'Code-mode execution was interrupted by controller ownership loss. No processed terminal result was retained; external effects may already have occurred. Execution was not retried.';
                                    $result = new ToolCallResult($reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey, $reference->toolCallId, $reference->orderIndex,
                                        ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => $text]]],
                                        true, ['type' => 'controller_interrupted', 'message' => $text, 'retryable' => false]);
                                }
                                $accepted = $this->artifacts->publish($result);
                            }
                            // Immutable publication can leave an unreferenced file on
                            // rollback, never accepted authority. Parking, first acceptance
                            // and the old-host fence commit together.
                            $this->deferred->acceptResult($identity, $accepted);
                            $this->executions->interrupt($identity, $reason);
                        });
                        // Controller attachment is not continuation authority. The
                        // captured explicit repair redrive releases this delivery fence.
                    }
                    foreach ($this->executions->activeIdentities($runId) as $identity) {
                        $this->transactions->transactional(function () use ($identity, $reason): void {
                            $this->deferred->requireContinuation($identity, true);
                            $this->executions->interrupt($identity, $reason);
                        });
                    }
                });
            });
        }
    }
}
