<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

/**
 * Event DTO dispatched through Symfony's EventDispatcher when a streaming
 * delta is observed during LLM platform invocation.
 *
 * Carries run/step context plus the raw Symfony AI delta so subscribers
 * can map it to transient RuntimeEvent values without a central instanceof
 * router.
 *
 * The event name used for dispatch is the delta class FQCN (e.g.,
 * TextDelta::class, ThinkingDelta::class), allowing subscribers to
 * register for specific delta types via getSubscribedEvents().
 *
 * Plain PHP class — does not extend Symfony's deprecated Event base class.
 */
final class RuntimeStreamDeltaEvent
{
    /** Set by the first subscriber that successfully maps this delta. */
    public bool $handled = false;

    public function __construct(
        public readonly string $runId,
        public readonly ?string $stepId,
        public readonly DeltaInterface $delta,
    ) {
    }
}
