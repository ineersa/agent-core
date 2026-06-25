<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Detects foreground non-interactive child subagent runs for extension hooks.
 *
 * Child workers inherit HATFIELD_APPROVAL_CHANNEL from the parent controller;
 * SafeGuard must not enter RequireApproval for those runs.
 */
final readonly class NoninteractiveChildRunProbe
{
    public function __construct(
        private EventStoreInterface $eventStore,
    ) {
    }

    public function isNoninteractiveChildRun(?string $runId): bool
    {
        if (null === $runId || '' === $runId) {
            return false;
        }

        foreach ($this->eventStore->allFor($runId) as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            $inner = $event->payload['payload'] ?? null;
            if (!\is_array($inner)) {
                return false;
            }

            $metadata = $inner['metadata'] ?? null;
            if (!\is_array($metadata)) {
                return false;
            }

            $session = $metadata['session'] ?? [];
            if (!\is_array($session) || 'agent_child' !== ($session['kind'] ?? null)) {
                return false;
            }

            return false === ($session['interactive'] ?? true);
        }

        return false;
    }
}
