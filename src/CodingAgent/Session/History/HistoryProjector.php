<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

use function Symfony\Component\String\u;

/**
 * Builds ordered retained history from the canonical run event stream.
 *
 * Model:
 *  - turn_advanced appends a turn and moves position to that turn.
 *  - history_position_set selects 0 or a retained turn without discarding.
 *  - history_tail_discarded(after_turn_no) drops later retained turns.
 *  - Absent position marker defaults to retained tip (or null when empty).
 */
final class HistoryProjector
{
    /**
     * @param list<RunEvent> $events
     */
    public function build(string $runId, array $events): HistoryDTO
    {
        if ([] === $events) {
            return new HistoryDTO(runId: $runId, turns: [], positionTurnNo: null);
        }

        $sorted = $this->sortBySeq($events);
        $activeTurnNos = [];
        /** @var array<int, array{anchorSeq: int, anchorIndex: int}> $turnInfo */
        $turnInfo = [];
        $positionTurnNo = null;

        foreach ($sorted as $index => $event) {
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                if ($turnNo <= 0) {
                    continue;
                }

                if (!\in_array($turnNo, $activeTurnNos, true)) {
                    $activeTurnNos[] = $turnNo;
                }

                $turnInfo[$turnNo] = [
                    'anchorSeq' => $event->seq,
                    'anchorIndex' => $index,
                ];
                $positionTurnNo = $turnNo;
                continue;
            }

            if (RunEventTypeEnum::HistoryPositionSet->value === $event->type) {
                $turnNo = (int) ($event->payload['position_turn_no'] ?? $event->turnNo);
                if (0 === $turnNo) {
                    $positionTurnNo = null;
                    continue;
                }
                if (\in_array($turnNo, $activeTurnNos, true)) {
                    $positionTurnNo = $turnNo;
                }
                continue;
            }

            if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
                $after = (int) ($event->payload['after_turn_no'] ?? 0);
                $activeTurnNos = array_values(array_filter(
                    $activeTurnNos,
                    static fn (int $t): bool => $t <= $after,
                ));
                if (0 === $after || [] === $activeTurnNos) {
                    $positionTurnNo = null;
                } elseif (\in_array($after, $activeTurnNos, true)) {
                    $positionTurnNo = $after;
                } elseif (null !== $positionTurnNo && $positionTurnNo > $after) {
                    $positionTurnNo = $activeTurnNos[array_key_last($activeTurnNos)];
                }
            }
        }

        if (null === $positionTurnNo && [] !== $activeTurnNos) {
            $positionTurnNo = $activeTurnNos[array_key_last($activeTurnNos)];
        }

        $turns = [];
        $count = \count($activeTurnNos);
        for ($i = 0; $i < $count; ++$i) {
            $turnNo = $activeTurnNos[$i];
            $info = $turnInfo[$turnNo] ?? null;
            if (null === $info) {
                continue;
            }

            $previousTurnNo = $i > 0 ? $activeTurnNos[$i - 1] : null;
            $displayRole = $this->classifyDisplayRole($turnNo, $info['anchorIndex'], $sorted, $previousTurnNo);
            $rawTitle = $this->titleForTurn($turnNo, $info['anchorIndex'], $sorted, $previousTurnNo);
            $title = $this->sanitizeTurnTitle($rawTitle);
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = $this->placeholderTitleForTurn($turnNo, $displayRole);
            }
            $promptText = $this->fullUserPromptForTurn(
                $turnNo,
                $info['anchorIndex'],
                $sorted,
                $previousTurnNo,
                $displayRole,
            );

            $turns[] = new HistoryTurnDTO(
                turnNo: $turnNo,
                title: $title,
                displayRole: $displayRole,
                promptText: $promptText,
            );
        }

        // Drop position if it failed to materialize.
        $retainedNos = array_map(static fn (HistoryTurnDTO $t): int => $t->turnNo, $turns);
        if (null !== $positionTurnNo && !\in_array($positionTurnNo, $retainedNos, true)) {
            $positionTurnNo = [] !== $retainedNos ? $retainedNos[array_key_last($retainedNos)] : null;
        }

        return new HistoryDTO(
            runId: $runId,
            turns: $turns,
            positionTurnNo: $positionTurnNo,
        );
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function titleForTurn(int $turnNo, int $anchorIndex, array $sortedEvents, ?int $previousTurnNo): string
    {
        $previousAnchorIndex = $this->previousAnchorIndex($previousTurnNo, $anchorIndex, $sortedEvents);

        for ($i = $anchorIndex - 1; $i > $previousAnchorIndex; --$i) {
            $event = $sortedEvents[$i];
            $text = $this->extractUserVisibleText($event);
            if ('' !== $text) {
                return $this->truncate($text, 80);
            }
        }

        $anchorEvent = $sortedEvents[$anchorIndex] ?? null;
        $stepId = \is_array($anchorEvent?->payload) && \is_string($anchorEvent->payload['step_id'] ?? null)
            ? $anchorEvent->payload['step_id']
            : '';
        if (str_starts_with($stepId, 'advance-after-tools')) {
            $text = $this->assistantTitleAfterAnchor($turnNo, $anchorIndex, $sortedEvents);
            if ('' !== $text) {
                return $this->truncate($text, 80);
            }
        }

        if (null === $previousTurnNo) {
            foreach ($sortedEvents as $event) {
                if (RunEventTypeEnum::RunStarted->value === $event->type) {
                    $text = $this->extractInitialUserText($event);
                    if ('' !== $text) {
                        return $this->truncate($text, 80);
                    }
                    break;
                }
            }
        }

        return "Turn {$turnNo}";
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function fullUserPromptForTurn(
        int $turnNo,
        int $anchorIndex,
        array $sortedEvents,
        ?int $previousTurnNo,
        string $displayRole,
    ): string {
        if ('user' !== $displayRole) {
            return '';
        }

        $previousAnchorIndex = $this->previousAnchorIndex($previousTurnNo, $anchorIndex, $sortedEvents);
        for ($i = $anchorIndex - 1; $i > $previousAnchorIndex; --$i) {
            $event = $sortedEvents[$i];
            if (RunEventTypeEnum::AgentCommandApplied->value !== $event->type) {
                continue;
            }
            $kind = \is_string($event->payload['kind'] ?? null) ? $event->payload['kind'] : null;
            if (!\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
                continue;
            }
            $text = \is_string($event->payload['text'] ?? null) ? $event->payload['text'] : '';
            if ('' === $text) {
                $text = $this->extractTextFromMessagePayload($event->payload['message'] ?? null);
            }
            if ('' !== $text) {
                return $text;
            }
        }

        if (null === $previousTurnNo) {
            foreach ($sortedEvents as $event) {
                if (RunEventTypeEnum::RunStarted->value === $event->type) {
                    return $this->extractInitialUserText($event);
                }
            }
        }

        return '';
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function previousAnchorIndex(?int $previousTurnNo, int $anchorIndex, array $sortedEvents): int
    {
        if (null === $previousTurnNo) {
            return -1;
        }

        for ($i = $anchorIndex - 1; $i >= 0; --$i) {
            $event = $sortedEvents[$i];
            if (RunEventTypeEnum::TurnAdvanced->value !== $event->type) {
                continue;
            }
            $advancedTurnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($advancedTurnNo === $previousTurnNo) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function assistantTitleAfterAnchor(int $turnNo, int $anchorIndex, array $sortedEvents): string
    {
        $limit = \count($sortedEvents);
        for ($i = $anchorIndex + 1; $i < $limit; ++$i) {
            $event = $sortedEvents[$i];
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                break;
            }
            if (RunEventTypeEnum::LlmStepCompleted->value !== $event->type) {
                continue;
            }
            if ($event->turnNo !== $turnNo) {
                continue;
            }
            $payload = $event->payload;
            $text = \is_string($payload['text'] ?? null) && '' !== $payload['text']
                ? $payload['text']
                : $this->extractAssistantText($payload['assistant_message'] ?? null);

            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    private function extractUserVisibleText(RunEvent $event): string
    {
        $payload = $event->payload;

        if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
            $kind = \is_string($payload['kind'] ?? null) ? $payload['kind'] : null;
            if (\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
                $text = \is_string($payload['text'] ?? null) ? $payload['text'] : '';
                if ('' === $text) {
                    $text = $this->extractTextFromMessagePayload($payload['message'] ?? null);
                }

                if ('' !== $text) {
                    return $text;
                }
            }
        }

        if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
            $text = \is_string($payload['text'] ?? null) && '' !== $payload['text']
                ? $payload['text']
                : $this->extractAssistantText($payload['assistant_message'] ?? null);

            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    private function extractInitialUserText(RunEvent $event): string
    {
        $innerPayload = \is_array($event->payload['payload'] ?? null) ? $event->payload['payload'] : [];
        $messages = \is_array($innerPayload['messages'] ?? null) ? $innerPayload['messages'] : [];

        foreach ($messages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            if ('user' !== (string) ($msg['role'] ?? '')) {
                continue;
            }
            $text = $this->extractTextFromContent($msg['content'] ?? []);
            if ('' !== $text) {
                return $text;
            }
        }

        $topMessages = \is_array($event->payload['messages'] ?? null) ? $event->payload['messages'] : [];
        foreach ($topMessages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            if ('user' !== (string) ($msg['role'] ?? '')) {
                continue;
            }
            $text = $this->extractTextFromContent($msg['content'] ?? []);
            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    private function extractTextFromMessagePayload(mixed $messagePayload): string
    {
        if (!\is_array($messagePayload)) {
            return '';
        }

        return $this->extractTextFromContent($messagePayload['content'] ?? []);
    }

    private function extractTextFromContent(mixed $content): string
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

    private function extractAssistantText(mixed $assistantMessage): string
    {
        return \is_array($assistantMessage)
            ? $this->extractTextFromContent($assistantMessage['content'] ?? null)
            : '';
    }

    private function truncate(string $text, int $maxLen): string
    {
        return u($text)->truncate($maxLen, '…')->toString();
    }

    private function sanitizeTurnTitle(string $title): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $title);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ('' === $text) {
            return '';
        }

        $text = preg_replace('/^>\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^[-*]\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^#+\s+/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function classifyDisplayRole(int $turnNo, int $anchorIndex, array $sortedEvents, ?int $previousTurnNo): string
    {
        $anchorEvent = $sortedEvents[$anchorIndex] ?? null;
        $stepId = \is_array($anchorEvent?->payload) && \is_string($anchorEvent->payload['step_id'] ?? null)
            ? $anchorEvent->payload['step_id']
            : '';

        $previousAnchorIndex = $this->previousAnchorIndex($previousTurnNo, $anchorIndex, $sortedEvents);
        for ($i = $anchorIndex - 1; $i > $previousAnchorIndex; --$i) {
            $event = $sortedEvents[$i];
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type) {
                $kind = \is_string($event->payload['kind'] ?? null) ? $event->payload['kind'] : null;
                if (\in_array($kind, ['steer', 'follow_up', 'append_message'], true)) {
                    return 'user';
                }
            }
        }

        if (str_starts_with($stepId, 'follow_up') || str_starts_with($stepId, 'steer')) {
            return 'user';
        }

        if (null === $previousTurnNo) {
            foreach ($sortedEvents as $event) {
                if (RunEventTypeEnum::RunStarted->value === $event->type) {
                    if ('' !== $this->extractInitialUserText($event)) {
                        return 'user';
                    }
                    break;
                }
            }
        }

        if (str_starts_with($stepId, 'advance-after-tools')) {
            return 'assistant';
        }

        return 'assistant';
    }

    private function placeholderTitleForTurn(int $turnNo, string $displayRole): string
    {
        return match ($displayRole) {
            'user' => 'User message (turn '.$turnNo.')',
            default => 'Assistant response (turn '.$turnNo.')',
        };
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function sortBySeq(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }
}
