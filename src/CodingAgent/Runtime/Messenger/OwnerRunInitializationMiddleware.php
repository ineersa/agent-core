<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Explicit acquisition/recovery before owner consumption, never on producer dispatch. */
final readonly class OwnerRunInitializationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ActiveRunContextInterface $registry,
        private EventStoreInterface $events,
        private RunStateRebuilderInterface $replay,
        private HatfieldSessionStore $sessions,
        private AgentChildRunDirectory $children,
        private RunLockManager $locks,
        private \Psr\Log\LoggerInterface $logger,
        private \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry $artifacts,
        private \Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery $transitionRecovery,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if (null === $received || 'run_control' !== $received->getTransportName()) {
            return $stack->next()->handle($envelope, $stack);
        }
        $message = $envelope->getMessage();
        $runId = match (true) {
            $message instanceof AbstractAgentBusMessage => $message->runId(),
            $message instanceof AttachRun, $message instanceof RepairSession, $message instanceof SelectHistoryPrompt => $message->runId,
            default => null,
        };
        if (null === $runId) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->locks->synchronized($runId, function () use ($runId, $message, $envelope, $stack): Envelope {
            // Maintenance performs recovery inside its correlated response boundary.
            if (!$message instanceof RepairSession && !$message instanceof SelectHistoryPrompt) {
                $this->initializeForOwner($runId, $message);
            }

            $handled = $stack->next()->handle($envelope, $stack);
            // Enqueue is not acceptance. Promote only after the owner committed
            // StartRun; deterministic launch redelivery can safely reach here again.
            if ($message instanceof StartRun && null !== $this->events->latestSequenceFor($runId)) {
                $child = $this->children->locate($runId);
                if (null !== $child && \Ineersa\AgentCore\Domain\Run\RunStatus::Running === $this->registry->requireLoaded($runId)->status) {
                    try {
                        $entry = $this->artifacts->promoteToRunningForwardOnly($child->parentRunId, $child->artifactId, new \DateTimeImmutable());
                        if (null !== $entry) {
                            $this->children->register($entry);
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->warning('child_run.artifact_running_persist_failed', ['run_id' => $runId, 'parent_run_id' => $child->parentRunId, 'artifact_id' => $child->artifactId, 'component' => 'run_control', 'event_type' => 'child_run.artifact_running_persist_failed', 'exception_class' => $exception::class]);
                    }
                }
            }

            return $handled;
        });
    }

    /** Explicit owner entry, including maintenance after its integrity-only refusal checks. */
    public function initializeForOwner(string $runId, object $message): void
    {
        $this->locks->synchronized($runId, function () use ($runId, $message): void {
            $this->transitionRecovery->recover($runId);
            try {
                $this->registry->requireLoaded($runId);
            } catch (RunContextNotLoadedException) {
                $this->logger->info('owner.run.initializing', ['run_id' => $runId, 'component' => 'run_control', 'event_type' => 'owner.run.initializing']);
                // This is the audited owner-entry boundary. Unknown identities never
                // become queued runs; worker replacement uses the actual envelope ID.
                if (!$this->sessions->exists($runId) && null === $this->children->locate($runId)) {
                    if ($message instanceof RepairSession || $message instanceof SelectHistoryPrompt) {
                        // Maintenance retains its narrow no-events/refusal response,
                        // without admitting unknown identities into the registry.
                        return;
                    }
                    throw new \RuntimeException('Owner initialization refused unregistered run: '.$runId);
                }
                if (null === $this->events->latestSequenceFor($runId)) {
                    if ($message instanceof RepairSession || $message instanceof SelectHistoryPrompt) {
                        return;
                    }
                    // Reservation alone does not authorize ordinary commands to
                    // recreate lost history. Start and shell entry, or cancellation
                    // of a reserved child before StartRun, are explicit new-run paths.
                    if (!$message instanceof StartRun && !$message instanceof ApplyShellCommand
                        && !($message instanceof ApplyCommand && 'cancel' === $message->kind && null !== $this->children->locate($runId))
                    ) {
                        throw new \RuntimeException('Owner recovery requires canonical history: '.$runId);
                    }
                    $this->registry->createNew($runId);
                } else {
                    $recovered = $this->replay->rebuildIfStale(RunState::queued($runId), $runId)->rebuiltState;
                    if (null === $recovered || $recovered->runId !== $runId || 0 >= $recovered->lastSeq) {
                        throw new \RuntimeException('Owner recovery produced no state: '.$runId);
                    }
                    $this->registry->loadRecovered($recovered);
                }
            }
        });
    }
}
