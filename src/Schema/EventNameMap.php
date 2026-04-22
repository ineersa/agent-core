<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Schema;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

final class EventNameMap
{
    /**
     * @var array<string, string>
     */
    private const array INTERNAL_TO_PUBLIC = [
        CoreLifecycleEventType::AGENT_START => CoreLifecycleEventType::AGENT_START,
        CoreLifecycleEventType::TURN_START => CoreLifecycleEventType::TURN_START,
        CoreLifecycleEventType::MESSAGE_START => CoreLifecycleEventType::MESSAGE_START,
        CoreLifecycleEventType::MESSAGE_UPDATE => CoreLifecycleEventType::MESSAGE_UPDATE,
        CoreLifecycleEventType::MESSAGE_END => CoreLifecycleEventType::MESSAGE_END,
        CoreLifecycleEventType::TOOL_EXECUTION_START => CoreLifecycleEventType::TOOL_EXECUTION_START,
        CoreLifecycleEventType::TOOL_EXECUTION_UPDATE => CoreLifecycleEventType::TOOL_EXECUTION_UPDATE,
        CoreLifecycleEventType::TOOL_EXECUTION_END => CoreLifecycleEventType::TOOL_EXECUTION_END,
        CoreLifecycleEventType::TURN_END => CoreLifecycleEventType::TURN_END,
        CoreLifecycleEventType::AGENT_END => CoreLifecycleEventType::AGENT_END,
    ];

    /**
     * Returns the public-facing name for an internal event type.
     */
    public function toPublic(string $internalType): string
    {
        return self::INTERNAL_TO_PUBLIC[$internalType] ?? $internalType;
    }

    /**
     * Returns the internal event name for a public stream event type.
     */
    public function toInternal(string $publicType): string
    {
        return self::publicToInternalMap()[$publicType] ?? $publicType;
    }

    /**
     * Exposes the static internal-to-public mapping for diagnostics and contract tests.
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return self::INTERNAL_TO_PUBLIC;
    }

    /**
     * @return array<string, string>
     */
    private static function publicToInternalMap(): array
    {
        static $map = null;

        if (null === $map) {
            $map = array_flip(self::INTERNAL_TO_PUBLIC);
        }

        return $map;
    }
}
