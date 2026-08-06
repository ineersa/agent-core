<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

use function Symfony\Component\String\u;

/**
 * Builds a linear active-history projection from the canonical run event stream.
 *
 * Model:
 *  - turn_advanced appends a turn to the active ordered list and sets the tip.
 *  - leaf_set moves the selected tip (undo/redo cursor) without discarding.
 *  - history_tail_discarded(after_turn_no) permanently drops later active turns
 *    from normal projections (events remain audit-only in events.jsonl).
 *  - New turns after a discard use globally unique turn numbers and append
 *    normally to the retained list.
 *
 * parentTurnNo / childTurnNos on nodes form a pure linear chain (previous/next
 * active turn) so existing row consumers keep a flat walk without branch UX.
 *
 * @phpstan-type TurnInfo array<int, array{anchorSeq: int, anchorIndex: int, createdAt: \DateTimeImmutable, reason: string|null}>
 */
final class TurnTreeProjector
{
    /**
     * @param list<RunEvent> $events
     */
    public function build(string $runId, array $events): TurnTreeDTO
    {
        if ([] === $events) {
            return new TurnTreeDTO(
                runId: $runId,
                nodesByTurnNo: [],
                rootTurnNos: [],
                currentLeafTurnNo: null,
                activePathTurnNos: [],
            );
        }

        $sorted = $this->sortBySeq($events);
        $activeTurnNos = [];
        $turnInfo = [];
        $currentLeafTurnNo = null;

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
                    'createdAt' => $event->createdAt,
                    'reason' => null,
                ];
                $currentLeafTurnNo = $turnNo;
                continue;
            }

            if (RunEventTypeEnum::LeafSet->value === $event->type) {
                $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                if (0 === $turnNo) {
                    $currentLeafTurnNo = null;
                    continue;
                }
                if (\in_array($turnNo, $activeTurnNos, true)) {
                    $currentLeafTurnNo = $turnNo;
                }
                continue;
            }

            if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
                $after = (int) ($event->payload['after_turn_no'] ?? 0);
                $activeTurnNos = array_values(array_filter(
                    $activeTurnNos,
                    static fn (int $t): bool => $t <= $after,
                ));
                // Tip stays at retained boundary when that turn survived; otherwise last active or null.
                if (0 === $after || [] === $activeTurnNos) {
                    $currentLeafTurnNo = null;
                } elseif (\in_array($after, $activeTurnNos, true)) {
                    $currentLeafTurnNo = $after;
                } elseif (null !== $currentLeafTurnNo && $currentLeafTurnNo > $after) {
                    $currentLeafTurnNo = $activeTurnNos[array_key_last($activeTurnNos)];
                }
            }
        }

        // Old streams without leaf_set: tip is last active turn (already set).
        if (null === $currentLeafTurnNo && [] !== $activeTurnNos) {
            $currentLeafTurnNo = $activeTurnNos[array_key_last($activeTurnNos)];
        }

        $lastSeqs = $this->computeLastSeqs($turnInfo, $sorted);
        $nodesByTurnNo = [];
        $count = \count($activeTurnNos);

        for ($i = 0; $i < $count; ++$i) {
            $turnNo = $activeTurnNos[$i];
            $info = $turnInfo[$turnNo] ?? null;
            if (null === $info) {
                // Turn referenced only via leaf/discard without turn_advanced — skip.
                continue;
            }

            $parentTurnNo = $i > 0 ? $activeTurnNos[$i - 1] : null;
            $childTurnNos = $i < $count - 1 ? [$activeTurnNos[$i + 1]] : [];
            $rawTitle = $this->titleForTurn($turnNo, $info['anchorIndex'], $sorted, $parentTurnNo);
            $title = $this->sanitizeTurnTitle($rawTitle);
            $displayRole = $this->classifyDisplayRole($turnNo, $info['anchorIndex'], $sorted, $parentTurnNo);
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = $this->placeholderTitleForTurn($turnNo, $displayRole);
            }
            $fullPrompt = $this->fullUserPromptForTurn($turnNo, $info['anchorIndex'], $sorted, $parentTurnNo, $displayRole);

            $nodesByTurnNo[$turnNo] = new TurnTreeNodeDTO(
                turnNo: $turnNo,
                parentTurnNo: $parentTurnNo,
                childTurnNos: $childTurnNos,
                anchorSeq: $info['anchorSeq'],
                lastSeq: $lastSeqs[$turnNo] ?? $info['anchorSeq'],
                title: $title,
                promptPreview: $this->truncate($title, 60),
                createdAt: $info['createdAt'],
                isCurrentLeaf: $turnNo === $currentLeafTurnNo,
                reason: $info['reason'],
                displayRole: $displayRole,
                fullPromptText: $fullPrompt,
            );
        }

        // Recompute active list from nodes that actually materialized.
        $activePathTurnNos = array_values(array_filter(
            $activeTurnNos,
            static fn (int $t): bool => isset($nodesByTurnNo[$t]),
        ));
        $rootTurnNos = [] !== $activePathTurnNos ? [$activePathTurnNos[0]] : [];

        return new TurnTreeDTO(
            runId: $runId,
            nodesByTurnNo: $nodesByTurnNo,
            rootTurnNos: $rootTurnNos,
            currentLeafTurnNo: $currentLeafTurnNo,
            activePathTurnNos: $activePathTurnNos,
        );
    }

    /**
     * Active retained turns from root through target (inclusive).
     *
     * @param array<int, TurnTreeNodeDTO> $nodesByTurnNo
     *
     * @return list<int>
     */
    public static function activePathTo(int $targetTurnNo, array $nodesByTurnNo): array
    {
        if (!isset($nodesByTurnNo[$targetTurnNo])) {
            return [];
        }

        // Linear chain: walk parents upward then reverse.
        $path = [];
        $cursor = $targetTurnNo;
        $guard = 0;
        while (null !== $cursor) {
            if (++$guard > 10000) {
                throw new \RuntimeException('Cycle detected while walking linear history path.');
            }
            if (!isset($nodesByTurnNo[$cursor])) {
                throw new \RuntimeException(\sprintf('Dangling parent_turn_no %d while walking active turn path.', $cursor));
            }
            $path[] = $cursor;
            $cursor = $nodesByTurnNo[$cursor]->parentTurnNo;
        }

        return array_reverse($path);
    }

    /**
     * @param array<int, array{anchorSeq: int, ...}> $turnInfo
     * @param list<RunEvent>                         $sortedEvents
     *
     * @return array<int, int>
     */
    private function computeLastSeqs(array $turnInfo, array $sortedEvents): array
    {
        $lastSeqs = [];
        foreach ($turnInfo as $turnNo => $info) {
            $lastSeqs[$turnNo] = $info['anchorSeq'];
        }

        foreach ($sortedEvents as $event) {
            $eventTurn = $event->turnNo;
            if (isset($lastSeqs[$eventTurn])) {
                $lastSeqs[$eventTurn] = max($lastSeqs[$eventTurn], $event->seq);
            }
        }

        return $lastSeqs;
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function titleForTurn(int $turnNo, int $anchorIndex, array $sortedEvents, ?int $parentTurnNo): string
    {
        $parentAnchorIndex = $this->parentAnchorIndex($parentTurnNo, $anchorIndex, $sortedEvents);

        for ($i = $anchorIndex - 1; $i > $parentAnchorIndex; --$i) {
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

        if (null === $parentTurnNo) {
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
     * Full original user prompt for editor population (user-role turns only).
     *
     * @param list<RunEvent> $sortedEvents
     */
    private function fullUserPromptForTurn(
        int $turnNo,
        int $anchorIndex,
        array $sortedEvents,
        ?int $parentTurnNo,
        string $displayRole,
    ): string {
        if ('user' !== $displayRole) {
            return '';
        }

        $parentAnchorIndex = $this->parentAnchorIndex($parentTurnNo, $anchorIndex, $sortedEvents);
        for ($i = $anchorIndex - 1; $i > $parentAnchorIndex; --$i) {
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

        if (null === $parentTurnNo) {
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
    private function parentAnchorIndex(?int $parentTurnNo, int $anchorIndex, array $sortedEvents): int
    {
        if (null === $parentTurnNo) {
            return -1;
        }

        for ($i = $anchorIndex - 1; $i >= 0; --$i) {
            $event = $sortedEvents[$i];
            if (RunEventTypeEnum::TurnAdvanced->value !== $event->type) {
                continue;
            }
            $advancedTurnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($advancedTurnNo === $parentTurnNo) {
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
    private function classifyDisplayRole(int $turnNo, int $anchorIndex, array $sortedEvents, ?int $parentTurnNo): string
    {
        $anchorEvent = $sortedEvents[$anchorIndex] ?? null;
        $stepId = \is_array($anchorEvent?->payload) && \is_string($anchorEvent->payload['step_id'] ?? null)
            ? $anchorEvent->payload['step_id']
            : '';

        $parentAnchorIndex = $this->parentAnchorIndex($parentTurnNo, $anchorIndex, $sortedEvents);
        for ($i = $anchorIndex - 1; $i > $parentAnchorIndex; --$i) {
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

        if (null === $parentTurnNo) {
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
