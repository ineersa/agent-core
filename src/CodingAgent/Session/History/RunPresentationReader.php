<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;

/** Explicit historical presentation read, not execution-state acquisition. */
final readonly class RunPresentationReader
{
    public function __construct(
        private EventStoreInterface $events,
        private RunLockManager $locks,
        private ReplayAssistantMessageFactory $assistantFactory,
        private ToolExecutionEndPayloadCodec $toolCodec,
        private RunHistorySourceProviderInterface $sources,
    ) {
    }

    public function read(string $runId, int $limit, int $summaryChars): ?RunPresentationDTO
    {
        if ($limit < 0 || $summaryChars < 1) {
            throw new \InvalidArgumentException('Invalid presentation bounds.');
        }

        return $this->locks->synchronized($runId, function () use ($runId, $limit, $summaryChars): ?RunPresentationDTO {
            $cut = $this->events->latestSequenceFor($runId);
            if (null === $cut) {
                return null;
            }
            if (0 === $limit) {
                $source = $this->sources->historySource($runId);

                return $source->log->presentationSummary($source->path, $runId, $cut);
            }
            $plan = HistoryReplayPlan::build($this->events->rangeFor($runId, 1, $cut));
            $projector = new RunPresentationProjector($this->assistantFactory, $this->toolCodec, $limit, $summaryChars);
            foreach ($this->events->rangeFor($runId, 1, $cut) as $event) {
                $projector->observe($event, $plan->includes($event));
            }

            return $projector->presentation($plan->positionTurnNo, $cut);
        });
    }
}
