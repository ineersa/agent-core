<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Builds flat retained history from the canonical run event stream.
 *
 * One ordered forward scan:
 *  - RunStarted supplies the initial human prompt for the first TurnAdvanced
 *  - Applied human follow_up/steer text is attached to the next TurnAdvanced
 *  - append_message is excluded (generated context/reminder, not user input)
 *  - every positive TurnAdvanced is retained (internal tool/shell/assistant included)
 *  - history_position_set selects 0 or a retained anchor exactly
 *  - history_tail_discarded slices retained anchors and sparse prompts
 *  - position is an int; 0 means before first / empty (never null)
 */
final class HistoryProjector
{
    /** @param iterable<RunEvent> $events */
    public function replayPlan(iterable $events, ?int $positionTurnNo = null): HistoryReplayPlan
    {
        return HistoryReplayPlan::build($events, $positionTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     */
    public function build(array $events): HistoryDTO
    {
        if ([] === $events) {
            return new HistoryDTO(retainedTurnNos: [], promptsByTurnNo: [], positionTurnNo: 0);
        }

        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        $retention = new HistoryRetentionTracker();
        /** @var array<int, string> $promptsByTurnNo */
        $promptsByTurnNo = [];
        $initialPrompt = null;
        $pendingHumanPrompt = null;

        foreach ($sorted as $event) {
            $retention->observe($event);
            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                $text = self::extractInitialUserText($event);
                if ('' !== $text) {
                    $initialPrompt = $text;
                }
                continue;
            }

            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
                $kind = \is_string($event->payload['kind'] ?? null) ? $event->payload['kind'] : null;
                // Only honest human input seeds a selectable prompt.
                // append_message is generated (context budget / completion) — not user history.
                if (!\in_array($kind, ['follow_up', 'steer'], true)) {
                    continue;
                }
                $text = \is_string($event->payload['text'] ?? null) ? $event->payload['text'] : '';
                if ('' === $text) {
                    $message = $event->payload['message'] ?? null;
                    if (\is_array($message)) {
                        $text = self::extractTextFromContent($message['content'] ?? []);
                    }
                }
                if ('' !== $text) {
                    // Latest applied human prompt wins when multiple precede one anchor.
                    $pendingHumanPrompt = $text;
                }
                continue;
            }

            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                if ($turnNo <= 0) {
                    continue;
                }

                // Attach pending human prompt (or initial RunStarted prompt for first anchor).
                if (null !== $pendingHumanPrompt) {
                    $promptsByTurnNo[$turnNo] = $pendingHumanPrompt;
                    $pendingHumanPrompt = null;
                } elseif (null !== $initialPrompt) {
                    // First retained anchor receives the session-start human prompt once.
                    $promptsByTurnNo[$turnNo] = $initialPrompt;
                }
                // Never re-attach the session-start prompt to later internal anchors.
                $initialPrompt = null;

                continue;
            }

            if (RunEventTypeEnum::HistoryPositionSet->value === $event->type) {
                continue;
            }

            if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
                foreach (array_keys($promptsByTurnNo) as $promptTurn) {
                    if (!\in_array($promptTurn, $retention->retainedTurnNos, true)) {
                        unset($promptsByTurnNo[$promptTurn]);
                    }
                }
                $pendingHumanPrompt = null;
                // Compaction events leave pending human prompt intact (not handled here).
            }
        }

        return new HistoryDTO(
            retainedTurnNos: $retention->retainedTurnNos,
            promptsByTurnNo: $promptsByTurnNo,
            positionTurnNo: $retention->positionTurnNo,
        );
    }

    private static function extractInitialUserText(RunEvent $event): string
    {
        $innerPayload = \is_array($event->payload['payload'] ?? null) ? $event->payload['payload'] : [];
        $nested = \is_array($innerPayload['messages'] ?? null) ? $innerPayload['messages'] : [];
        $top = \is_array($event->payload['messages'] ?? null) ? $event->payload['messages'] : [];

        foreach ([$nested, $top] as $messages) {
            foreach ($messages as $msg) {
                if (!\is_array($msg) || 'user' !== (string) ($msg['role'] ?? '')) {
                    continue;
                }
                $text = self::extractTextFromContent($msg['content'] ?? []);
                if ('' !== $text) {
                    return $text;
                }
            }
        }

        return '';
    }

    private static function extractTextFromContent(mixed $content): string
    {
        if (!\is_array($content) || [] === $content) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }
}
