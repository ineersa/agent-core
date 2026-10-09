<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Bootstrap;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Event\RunEventPublishedEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Owner-local temporary display products, never a second execution-state registry. */
final class SessionBootstrapProducer
{
    private ?string $runId = null;
    /** @var list<TranscriptBlock> */
    private array $blocks = [];
    private ?\Throwable $projectionFailure = null;

    public function __construct(
        private readonly SessionReplayCoordinator $coordinator,
        private readonly ActiveRunContextInterface $registry,
        private readonly PendingTransitionRecovery $recovery,
        private readonly RunHistorySourceProviderInterface $sources,
        private readonly HatfieldSessionStore $sessions,
        private readonly SessionBootstrapSpoolStore $spools,
        private readonly LoggerInterface $logger,
        private readonly SessionBootstrapEmissionGate $emissionGate,
    ) {
    }

    /** Called under the existing owner lock, before attach changes canonical state. */
    public function prepare(string $runId): RunState
    {
        if (null !== $this->runId) {
            throw new \LogicException('Bootstrap projection is already in use.');
        }
        if (!$this->sessions->exists($runId)) {
            throw new \RuntimeException('Bootstrap requires a registered parent session.');
        }
        $this->emissionGate->prepare($runId);
        $this->recovery->recover($runId);
        try {
            $state = $this->registry->requireLoaded($runId);
            $result = $this->coordinator->display($state);
        } catch (RunContextNotLoadedException $exception) {
            $this->logger->debug('session.bootstrap.cold_owner', ['run_id' => $runId, 'session_id' => $runId, 'component' => 'session_bootstrap', 'event_type' => 'session.bootstrap.cold_owner', 'exception_class' => $exception::class]);
            $result = $this->coordinator->reconstruct(RunState::queued($runId), withTranscript: true);
            if (null === $result || $result->state->lastSeq < 1) {
                throw new \RuntimeException('Bootstrap recovery produced no canonical state.');
            }
            $this->registry->loadRecovered($result->state);
        }
        $this->runId = $runId;
        $this->blocks = $result->blocks;
        $this->projectionFailure = null;

        return $result->state;
    }

    #[AsEventListener]
    public function onPublished(RunEventPublishedEvent $published): void
    {
        if ($published->event->runId !== $this->runId || null !== $this->projectionFailure) {
            return;
        }
        try {
            $this->blocks = $this->coordinator->extendDisplay($this->blocks, $published->event);
        } catch (\Throwable $exception) {
            // A disposable display failure must not undo an accepted attach mutation.
            // Seal fails visibly after policy completes; no partial view is published.
            $this->projectionFailure = $exception;
            $this->blocks = [];
            $this->logger->warning('session.bootstrap.projection_failed', ['run_id' => $published->event->runId, 'session_id' => $published->event->runId, 'component' => 'session_bootstrap', 'event_type' => 'session.bootstrap.projection_failed', 'exception_class' => $exception::class]);
        }
    }

    public function seal(): SessionBootstrapDescriptorDTO
    {
        $runId = $this->runId;
        if (null === $runId) {
            throw new \LogicException('No prepared bootstrap.');
        }
        try {
            if (null !== $this->projectionFailure) {
                throw $this->projectionFailure;
            }
            $state = $this->registry->requireLoaded($runId);
            $source = $this->sources->historySource($runId);
            $cut = $source->log->historyCut($source->path, $runId);
            if (null === $cut || $cut['sequence'] !== $state->lastSeq) {
                throw new \RuntimeException('Bootstrap owner and canonical cut diverged.');
            }

            return $this->spools->seal($runId, $cut['sequence'], $cut['end_offset'], $cut['anchor'], $this->blocks,
                ['status' => $state->status->value, 'model' => $state->model, 'turn_no' => $state->turnNo]);
        } finally {
            $this->release();
        }
    }

    public function release(): void
    {
        $this->emissionGate->release();
        $this->runId = null;
        $this->blocks = [];
        $this->projectionFailure = null;
    }
}
