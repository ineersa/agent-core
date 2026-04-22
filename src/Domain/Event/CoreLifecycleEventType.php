<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

use Ineersa\AgentCore\Domain\Event\Lifecycle\AgentEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\AgentStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\MessageUpdateEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionStartEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\ToolExecutionUpdateEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\TurnEndEvent;
use Ineersa\AgentCore\Domain\Event\Lifecycle\TurnStartEvent;

/**
 * Central registry mapping lifecycle event type strings to concrete event classes, with ordering validation for event sequencing.
 */
final class CoreLifecycleEventType
{
    public const string AGENT_START = 'agent_start';
    public const string TURN_START = 'turn_start';
    public const string MESSAGE_START = 'message_start';
    public const string MESSAGE_UPDATE = 'message_update';
    public const string MESSAGE_END = 'message_end';
    public const string TOOL_EXECUTION_START = 'tool_execution_start';
    public const string TOOL_EXECUTION_UPDATE = 'tool_execution_update';
    public const string TOOL_EXECUTION_END = 'tool_execution_end';
    public const string TURN_END = 'turn_end';
    public const string AGENT_END = 'agent_end';

    /** @var list<string> */
    public const array ALL = [
        self::AGENT_START,
        self::TURN_START,
        self::MESSAGE_START,
        self::MESSAGE_UPDATE,
        self::MESSAGE_END,
        self::TOOL_EXECUTION_START,
        self::TOOL_EXECUTION_UPDATE,
        self::TOOL_EXECUTION_END,
        self::TURN_END,
        self::AGENT_END,
    ];

    public static function isCore(string $type): bool
    {
        return \in_array($type, self::ALL, true);
    }

    /**
     * Returns the associative array mapping event type strings to their PHP class names.
     *
     * @return array<string, class-string<RunEvent>>
     */
    public static function eventClassMap(): array
    {
        return [
            self::AGENT_START => AgentStartEvent::class,
            self::TURN_START => TurnStartEvent::class,
            self::MESSAGE_START => MessageStartEvent::class,
            self::MESSAGE_UPDATE => MessageUpdateEvent::class,
            self::MESSAGE_END => MessageEndEvent::class,
            self::TOOL_EXECUTION_START => ToolExecutionStartEvent::class,
            self::TOOL_EXECUTION_UPDATE => ToolExecutionUpdateEvent::class,
            self::TOOL_EXECUTION_END => ToolExecutionEndEvent::class,
            self::TURN_END => TurnEndEvent::class,
            self::AGENT_END => AgentEndEvent::class,
        ];
    }

    /**
     * Validates the chronological order of events and returns sorted array with extension prefix.
     *
     * @param list<RunEvent> $events
     *
     * @return list<string>
     */
    public static function validateOrder(array $events, string $extensionPrefix = 'ext_'): array
    {
        if ([] === $events) {
            return ['Lifecycle stream cannot be empty.'];
        }

        $violations = [];
        $lastIndex = \count($events) - 1;

        $turnOpen = false;
        $assistantMessageEndedInTurn = false;
        $waitingForToolPreflight = false;
        $lastToolOrderIndex = -1;
        $agentStartCount = 0;
        $agentEndCount = 0;

        foreach ($events as $index => $event) {
            $type = $event->type;
            $position = $index + 1;

            if (self::AGENT_START === $type) {
                ++$agentStartCount;
                if (0 !== $index) {
                    $violations[] = \sprintf('"%s" must be the first event (position %d).', self::AGENT_START, $position);
                }

                continue;
            }

            if (self::AGENT_END === $type) {
                ++$agentEndCount;

                if ($turnOpen) {
                    $violations[] = \sprintf('"%s" cannot be emitted before "turn_end" (position %d).', self::AGENT_END, $position);
                }

                if ($index !== $lastIndex) {
                    $violations[] = \sprintf('"%s" must be the final event (position %d).', self::AGENT_END, $position);
                }

                continue;
            }

            if (self::TURN_START === $type) {
                if ($turnOpen) {
                    $violations[] = \sprintf('Nested "turn_start" is not allowed (position %d).', $position);
                }

                $turnOpen = true;
                $assistantMessageEndedInTurn = false;
                $waitingForToolPreflight = false;
                $lastToolOrderIndex = -1;

                continue;
            }

            if (self::TURN_END === $type) {
                if (!$turnOpen) {
                    $violations[] = \sprintf('"turn_end" without an open turn (position %d).', $position);
                }

                if ($waitingForToolPreflight) {
                    $violations[] = \sprintf('"turn_end" before mandatory tool preflight (position %d).', $position);
                }

                $turnOpen = false;
                $assistantMessageEndedInTurn = false;
                $waitingForToolPreflight = false;

                continue;
            }

            if (!$turnOpen && self::isCore($type)) {
                $violations[] = \sprintf('Core event "%s" must be emitted inside an open turn (position %d).', $type, $position);
            }

            if (self::MESSAGE_END === $type) {
                $messageRole = $event->payload['message_role'] ?? null;
                if ('assistant' === $messageRole) {
                    $assistantMessageEndedInTurn = true;
                    $waitingForToolPreflight = (bool) ($event->payload['has_tool_calls'] ?? false);
                }

                continue;
            }

            if ($waitingForToolPreflight && str_starts_with($type, $extensionPrefix)) {
                $violations[] = \sprintf(
                    'Custom event "%s" cannot be emitted between assistant "message_end" and tool preflight start (position %d).',
                    $type,
                    $position,
                );
            }

            if (self::TOOL_EXECUTION_START === $type) {
                if (!$assistantMessageEndedInTurn) {
                    $violations[] = \sprintf('"tool_execution_start" requires assistant "message_end" barrier (position %d).', $position);
                }

                $waitingForToolPreflight = false;

                continue;
            }

            if (self::TOOL_EXECUTION_END === $type) {
                $orderIndex = $event->payload['order_index'] ?? null;
                if (\is_int($orderIndex)) {
                    if ($orderIndex < $lastToolOrderIndex) {
                        $violations[] = \sprintf('"tool_execution_end" order_index must be monotonic (position %d).', $position);
                    }

                    $lastToolOrderIndex = $orderIndex;
                }
            }
        }

        if (1 !== $agentStartCount) {
            $violations[] = \sprintf('Lifecycle stream must contain exactly one "%s".', self::AGENT_START);
        }

        if (1 !== $agentEndCount) {
            $violations[] = \sprintf('Lifecycle stream must contain exactly one "%s".', self::AGENT_END);
        }

        if (self::AGENT_START !== $events[0]->type) {
            $violations[] = \sprintf('Lifecycle stream must start with "%s".', self::AGENT_START);
        }

        if (self::AGENT_END !== $events[$lastIndex]->type) {
            $violations[] = \sprintf('Lifecycle stream must end with "%s".', self::AGENT_END);
        }

        if ($turnOpen) {
            $violations[] = 'Lifecycle stream contains an unclosed turn.';
        }

        return $violations;
    }
}
