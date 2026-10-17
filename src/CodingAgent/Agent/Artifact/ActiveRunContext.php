<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

final class ActiveRunContext implements ActiveRunContextInterface
{
    /** @var array<string, RunState> */
    private array $states = [];

    public function __construct(
        private readonly RunOperationalProjectionRepository $projectionRepository,
        private readonly HatfieldSessionStore $sessions,
        private readonly AgentChildRunDirectory $children,
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    public function createNew(string $runId): RunState
    {
        $session = $this->sessions->findSession($runId);
        $child = $this->children->locate($runId);
        if (null === $session && null === $child) {
            throw new \RuntimeException('Cannot create execution state for an unreserved run: '.$runId);
        }
        if (isset($this->states[$runId]) || null !== $this->eventStore->latestSequenceFor($runId)) {
            throw new \RuntimeException('Run already exists; explicit recovery required: '.$runId);
        }
        $operational = $this->projectionRepository->findOperationalStatus($runId);
        if ((null !== $operational && (0 !== $operational->lastEventSequence || \Ineersa\AgentCore\Domain\Run\RunStatus::Queued !== $operational->status))
            || (null !== $child && AgentArtifactStatusEnum::Pending !== $child->status)
        ) {
            throw new \RuntimeException('Reserved run has prior execution evidence but no canonical history: '.$runId);
        }
        $state = RunState::queued($runId)->with(['parentRunId' => null !== $child ? $child->parentRunId : $session?->parentId]);
        $this->publish($state);

        return $state;
    }

    public function loadRecovered(RunState $state): void
    {
        // The owner initialization boundary validates the canonical recovery
        // product. This method publishes that explicitly supplied product only.
        $this->publish($state);
    }

    public function requireLoaded(string $runId): RunState
    {
        return $this->states[$runId] ?? throw new \Ineersa\AgentCore\Contract\RunContextNotLoadedException('Run is not loaded; owner recovery required: '.$runId);
    }

    public function replaceCurrent(RunState $state): void
    {
        $this->requireLoaded($state->runId);
        $this->publish($state);
    }

    public function release(string $runId): void
    {
        unset($this->states[$runId]);
    }

    private function publish(RunState $state): void
    {
        try {
            $this->projectionRepository->replace($state);
        } catch (\Throwable $exception) {
            $this->release($state->runId);
            throw $exception;
        }
        $this->states[$state->runId] = $state;
    }
}
