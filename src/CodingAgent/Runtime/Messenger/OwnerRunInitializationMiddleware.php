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
use Ineersa\AgentCore\Domain\Message\AttachRun;
use Ineersa\AgentCore\Domain\Message\RepairSession;
use Ineersa\AgentCore\Domain\Message\SelectHistoryPrompt;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
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
                        return $stack->next()->handle($envelope, $stack);
                    }
                    throw new \RuntimeException('Owner initialization refused unregistered run: '.$runId);
                }
                if (null === $this->events->latestSequenceFor($runId)) {
                    if ($message instanceof RepairSession || $message instanceof SelectHistoryPrompt) {
                        return $stack->next()->handle($envelope, $stack);
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

            return $stack->next()->handle($envelope, $stack);
        });
    }
}
