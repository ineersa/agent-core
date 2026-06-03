<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * Validates the chronological order of lifecycle events in a run stream.
 *
 * Ensures that events follow the expected lifecycle sequence:
 * agent_start → turn_start → message_start/end ... → turn_end → agent_end,
 * with proper nesting, tool preflight barriers, and extension event constraints.
 */
final class LifecycleOrderValidator
{
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

            if (RunEventTypeEnum::AgentStart->value === $type) {
                ++$agentStartCount;
                if (0 !== $index) {
                    $violations[] = \sprintf('"%s" must be the first event (position %d).', RunEventTypeEnum::AgentStart->value, $position);
                }

                continue;
            }

            if (RunEventTypeEnum::AgentEnd->value === $type) {
                ++$agentEndCount;

                if ($turnOpen) {
                    $violations[] = \sprintf('"%s" cannot be emitted before "turn_end" (position %d).', RunEventTypeEnum::AgentEnd->value, $position);
                }

                if ($index !== $lastIndex) {
                    $violations[] = \sprintf('"%s" must be the final event (position %d).', RunEventTypeEnum::AgentEnd->value, $position);
                }

                continue;
            }

            if (RunEventTypeEnum::TurnStart->value === $type) {
                if ($turnOpen) {
                    $violations[] = \sprintf('Nested "turn_start" is not allowed (position %d).', $position);
                }

                $turnOpen = true;
                $assistantMessageEndedInTurn = false;
                $waitingForToolPreflight = false;
                $lastToolOrderIndex = -1;

                continue;
            }

            if (RunEventTypeEnum::TurnEnd->value === $type) {
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

            if (!$turnOpen && RunEventTypeEnum::isLifecycleType($type)) {
                $violations[] = \sprintf('Core event "%s" must be emitted inside an open turn (position %d).', $type, $position);
            }

            if (RunEventTypeEnum::MessageEnd->value === $type) {
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

            if (RunEventTypeEnum::ToolExecutionStart->value === $type) {
                if (!$assistantMessageEndedInTurn) {
                    $violations[] = \sprintf('"tool_execution_start" requires assistant "message_end" barrier (position %d).', $position);
                }

                $waitingForToolPreflight = false;

                continue;
            }

            if (RunEventTypeEnum::ToolExecutionEnd->value === $type) {
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
            $violations[] = \sprintf('Lifecycle stream must contain exactly one "%s".', RunEventTypeEnum::AgentStart->value);
        }

        if (1 !== $agentEndCount) {
            $violations[] = \sprintf('Lifecycle stream must contain exactly one "%s".', RunEventTypeEnum::AgentEnd->value);
        }

        if (RunEventTypeEnum::AgentStart->value !== $events[0]->type) {
            $violations[] = \sprintf('Lifecycle stream must start with "%s".', RunEventTypeEnum::AgentStart->value);
        }

        if (RunEventTypeEnum::AgentEnd->value !== $events[$lastIndex]->type) {
            $violations[] = \sprintf('Lifecycle stream must end with "%s".', RunEventTypeEnum::AgentEnd->value);
        }

        if ($turnOpen) {
            $violations[] = 'Lifecycle stream contains an unclosed turn.';
        }

        return $violations;
    }
}
