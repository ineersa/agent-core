<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Normalizes agent-core RunEvent domain events into stable runtime protocol RuntimeEvent DTOs.
 *
 * This is the sole bridge between agent-core event types and the runtime protocol.
 * TUI code must never import RunEvent or AgentCore internals directly.
 *
 * Delegates mapping to RuntimeEventTranslator which uses an explicit dispatch
 * table keyed by RunEventTypeEnum.
 */
final class RuntimeEventMapper
{
    public function __construct(
        private readonly RuntimeEventTranslator $translator,
    ) {
    }

    /**
     * Convert a single RunEvent to a RuntimeEvent with normalized type and payload.
     *
     * Returns null when the AgentCore event should not appear in the runtime
     * stream (e.g. internal bookkeeping events like tool_batch_committed).
     */
    public function toRuntimeEvent(RunEvent $runEvent): ?RuntimeEvent
    {
        return $this->translator->translate($runEvent);
    }
}
