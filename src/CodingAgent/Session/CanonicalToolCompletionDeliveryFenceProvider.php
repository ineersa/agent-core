<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;

/** Scalar canonical control indexes survive disposal by rebuilding from events. */
final readonly class CanonicalToolCompletionDeliveryFenceProvider implements ToolCompletionDeliveryFenceProviderInterface
{
    public function __construct(
        private SessionRunEventStore $events,
        private RunHistoryIndex $history,
        private OwningRootSessionResolverInterface $roots,
        private HatfieldSessionRepository $sessions,
    ) {
    }

    public function capture(string $runId): ToolCompletionDeliveryFenceDTO
    {
        $source = $this->events->historySource($runId);
        $control = $this->history->completionDeliveryGenerations($source->log, $source->path, $runId);
        $root = $this->roots->resolveOwningRootSessionId($runId);

        return new ToolCompletionDeliveryFenceDTO($control['wake'], $control['history'], $this->sessions->controllerGeneration($root));
    }
}
