<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

use function Symfony\Component\String\u;

/**
 * Builds a {@see TurnTreeDTO} from the canonical run event stream.
 *
 * Handles three stream shapes:
 *  - Old linear streams (no leaf_set / parent_turn_no): derives a single
 *    linear branch where parent_turn_no = turn_no - 1 and the current leaf
 *    is the highest turn number.
 *  - New linear streams (explicit parent_turn_no + leaf_set): uses explicit
 *    tree metadata from events.jsonl.
 *  - Branch streams (leaf_set switching to an earlier turn): builds the
 *    full tree and identifies the active branch path.
 *
 * This is a pure domain service with no side effects and no DB/IO dependency.
 *
 * @phpstan-type TurnInfo array<int, array{parentTurnNo: int|null, anchorSeq: int, anchorIndex: int, createdAt: \DateTimeImmutable, reason: string|null}>
 */
final class TurnTreeProjector
{
    /**
     * Build a turn tree from an unsorted event list.
     *
     * @param list<RunEvent> $events
     *
     * @throws \RuntimeException if a cycle is detected or a parent turn reference is missing from the stream
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

        // Find turn_advanced and leaf_set events.
        $turnAdvancedEvents = [];
        $leafSetEvents = [];

        foreach ($sorted as $index => $event) {
            if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
                $turnAdvancedEvents[$index] = $event;
            } elseif (RunEventTypeEnum::LeafSet->value === $event->type) {
                $leafSetEvents[$index] = $event;
            }
        }

        // Determine whether this is a new-style stream (has explicit parent_turn_no
        // in at least one turn_advanced payload, or has any leaf_set).
        $hasExplicitTreeMetadata = $this->hasExplicitTreeMetadata($turnAdvancedEvents, $leafSetEvents);

        // Build intermediate data.
        // turnInfo[turnNo] = { parentTurnNo, anchorSeq, anchorIndex, reason }
        $turnInfo = [];

        foreach ($turnAdvancedEvents as $index => $event) {
            $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);

            if ($hasExplicitTreeMetadata) {
                $parentTurnNo = \array_key_exists('parent_turn_no', $event->payload)
                    ? (\is_int($event->payload['parent_turn_no']) ? $event->payload['parent_turn_no'] : null)
                    : null;
            } else {
                // Old linear stream: parent is the previous turn number.
                $parentTurnNo = $turnNo > 1 ? $turnNo - 1 : null;
            }

            $turnInfo[$turnNo] = [
                'parentTurnNo' => $parentTurnNo,
                'anchorSeq' => $event->seq,
                'anchorIndex' => $index,
                'createdAt' => $event->createdAt,
            ];
        }

        // Also collect turn_branched events for any additional metadata.
        // turn_branched is explicit new-style tree metadata.  Future emitters
        // (e.g. SESSION-07 rewind pipeline) MUST provide 'turn_no' and
        // 'parent_turn_no' (nullable for root) in the payload, and set the
        // correct RunEvent::$turnNo matching the payload's turn_no.
        foreach ($sorted as $index => $event) {
            if (RunEventTypeEnum::TurnBranched->value === $event->type) {
                $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
                $parentTurnNo = \array_key_exists('parent_turn_no', $event->payload)
                    ? (\is_int($event->payload['parent_turn_no']) ? $event->payload['parent_turn_no'] : null)
                    : null;
                $reason = isset($event->payload['reason']) && \is_string($event->payload['reason'])
                    ? $event->payload['reason']
                    : null;

                if (!isset($turnInfo[$turnNo])) {
                    $turnInfo[$turnNo] = [
                        'parentTurnNo' => $parentTurnNo,
                        'anchorSeq' => $event->seq,
                        'anchorIndex' => $index,
                        'createdAt' => $event->createdAt,
                        'reason' => $reason,
                    ];
                } elseif (null !== $reason) {
                    $turnInfo[$turnNo]['reason'] = $reason;
                }
            }
        }

        // Determine current leaf.
        $lastLeafSet = [] !== $leafSetEvents ? end($leafSetEvents) : null;
        $currentLeafTurnNo = null;
        if (null !== $lastLeafSet) {
            $currentLeafTurnNo = (int) ($lastLeafSet->payload['turn_no'] ?? 0);
        } elseif ([] !== $turnInfo) {
            // Old stream without leaf_set: current leaf is the highest turn number.
            $currentLeafTurnNo = max(array_keys($turnInfo));
        }

        // Compute children map.
        $childMap = []; // parentTurnNo → list<int> childTurnNos
        foreach ($turnInfo as $turnNo => $info) {
            $parent = $info['parentTurnNo'];
            if (null !== $parent) {
                $childMap[$parent][] = $turnNo;
            }
        }

        // Collect known turn numbers from the full canonical stream so that
        // walkActivePath can include parent turns that have events but no
        // turn_advanced/turn_branched anchor (e.g. turn 1 introduced as the
        // parent of a rewinded branch).
        $knownTurnNos = [];
        foreach ($sorted as $event) {
            if ($event->turnNo > 0) {
                $knownTurnNos[$event->turnNo] = true;
            }
        }
        foreach ($turnInfo as $turnNo => $_) {
            $knownTurnNos[$turnNo] = true;
        }

        // Compute active path from current leaf to root.
        $activePathTurnNos = $this->walkActivePath($currentLeafTurnNo, $turnInfo, $knownTurnNos);

        // Compute lastSeq for each turn as the max event sequence scoped to that turn
        // (grouped by RunEvent::$turnNo), falling back to anchor seq for turns with no
        // additional scoped events beyond their turn_advanced anchor.
        $lastSeqs = $this->computeLastSeqs($turnInfo, $sorted);

        // Build nodes.
        $nodesByTurnNo = [];
        $rootTurnNos = [];

        foreach ($turnInfo as $turnNo => $info) {
            $title = $this->titleForTurn($turnNo, $info['anchorIndex'], $sorted);

            $node = new TurnTreeNodeDTO(
                turnNo: $turnNo,
                parentTurnNo: $info['parentTurnNo'],
                childTurnNos: $childMap[$turnNo] ?? [],
                anchorSeq: $info['anchorSeq'],
                lastSeq: $lastSeqs[$turnNo] ?? $info['anchorSeq'],
                title: $title,
                promptPreview: $this->truncate($title, 60),
                createdAt: $info['createdAt'],
                isCurrentLeaf: $turnNo === $currentLeafTurnNo,
                reason: $info['reason'] ?? null,
            );

            $nodesByTurnNo[$turnNo] = $node;

            if (null === $info['parentTurnNo']) {
                $rootTurnNos[] = $turnNo;
            }
        }

        return new TurnTreeDTO(
            runId: $runId,
            nodesByTurnNo: $nodesByTurnNo,
            rootTurnNos: $rootTurnNos,
            currentLeafTurnNo: $currentLeafTurnNo,
            activePathTurnNos: $activePathTurnNos,
        );
    }

    /**
     * Compute the path from root to an arbitrary leaf turn in the tree.
     *
     * Walks the parentTurnNo chain upward from the target turn to the root,
     * then returns the list in root-to-leaf order.
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

        $path = [];
        $visited = [];
        $cursor = $targetTurnNo;

        while (null !== $cursor) {
            if (\in_array($cursor, $visited, true)) {
                throw new \RuntimeException(\sprintf('Cycle detected in turn tree at turn %d.', $cursor));
            }

            $visited[] = $cursor;
            $path[] = $cursor;

            $node = $nodesByTurnNo[$cursor] ?? null;
            if (null === $node) {
                break;
            }

            $cursor = $node->parentTurnNo;
        }

        return array_reverse($path);
    }

    /**
     * Walk from the current leaf turn number up to the root, collecting turn numbers.
     *
     * @param array<int, array{parentTurnNo: int|null, ...}> $turnInfo
     * @param array<int, true>                               $knownTurnNos set of turn numbers that exist in the
     *                                                                     canonical event stream
     *
     * @return list<int> Turn numbers in order from root to leaf
     *
     * @throws \RuntimeException if a cycle is detected or a parent turn is missing
     */
    private function walkActivePath(?int $currentLeafTurnNo, array $turnInfo, array $knownTurnNos = []): array
    {
        if (null === $currentLeafTurnNo || !isset($knownTurnNos[$currentLeafTurnNo])) {
            return [];
        }

        $path = [];
        $visited = [];
        $cursor = $currentLeafTurnNo;

        while (null !== $cursor) {
            if (\in_array($cursor, $visited, true)) {
                throw new \RuntimeException(\sprintf('Cycle detected in turn tree at turn %d.', $cursor));
            }

            $visited[] = $cursor;
            $path[] = $cursor;

            if (!isset($turnInfo[$cursor])) {
                // The cursor is a canonical turn number (has events in the stream)
                // but lacks a turn_advanced/turn_branched anchor — this is a valid
                // terminal root (e.g. turn 1 with user/assistant events but no
                // explicit turn boundary event). Stop the walk here.
                if (isset($knownTurnNos[$cursor])) {
                    break;
                }

                throw new \RuntimeException(\sprintf('Dangling parent_turn_no %d while walking active turn path.', $cursor));
            }

            $cursor = $turnInfo[$cursor]['parentTurnNo'];
        }

        // Reverse to get root-to-leaf order.
        return array_reverse($path);
    }

    /**
     * Check whether the stream carries explicit tree metadata.
     *
     * A stream has explicit metadata if any leaf_set event exists OR any
     * turn_advanced event has an explicit parent_turn_no key in its payload.
     *
     * @param array<int, RunEvent> $turnAdvancedEvents
     * @param array<int, RunEvent> $leafSetEvents
     */
    private function hasExplicitTreeMetadata(array $turnAdvancedEvents, array $leafSetEvents): bool
    {
        if ([] !== $leafSetEvents) {
            return true;
        }

        foreach ($turnAdvancedEvents as $event) {
            if (\array_key_exists('parent_turn_no', $event->payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute lastSeq for each turn by finding the maximum canonical event
     * sequence whose {@see RunEvent::$turnNo} belongs to that turn.
     *
     * Tree metadata events (leaf_set, turn_branched) carry the selected turn
     * number, so a rewind leaf_set updates the target leaf's lastSeq.
     * Abandoned sibling turns only capture events scoped to their own turn
     * number and never chase the canonical max from later branches.
     *
     * Each node is initialized to its anchor seq so a turn with only a
     * turn_advanced anchor (no scoped message/tool/metadata events) still
     * has a valid lastSeq.
     *
     * @param array<int, array{anchorSeq: int, ...}> $turnInfo
     * @param list<RunEvent>                         $sortedEvents sorted ascending by seq
     *
     * @return array<int, int>
     */
    private function computeLastSeqs(array $turnInfo, array $sortedEvents): array
    {
        // Initialize each turn to its anchor seq as a floor.
        $lastSeqs = [];
        foreach ($turnInfo as $turnNo => $info) {
            $lastSeqs[$turnNo] = $info['anchorSeq'];
        }

        // Walk all events, for each known turn take the max seq.
        foreach ($sortedEvents as $event) {
            $eventTurn = $event->turnNo;
            if (isset($lastSeqs[$eventTurn])) {
                $lastSeqs[$eventTurn] = max($lastSeqs[$eventTurn], $event->seq);
            }
        }

        return $lastSeqs;
    }

    /**
     * Compute a human-readable title for a turn.
     *
     * Walks backward through events before the turn_advanced anchor to find
     * the most recent user-visible text. Prefers user input text over
     * assistant output for identifying "what this turn is about."
     *
     * @param list<RunEvent> $sortedEvents
     */
    private function titleForTurn(int $turnNo, int $anchorIndex, array $sortedEvents): string
    {
        // Walk backward from the turn_advanced event to find a descriptive message.
        for ($i = $anchorIndex - 1; $i >= 0; --$i) {
            $event = $sortedEvents[$i];
            $text = $this->extractUserVisibleText($event);

            if ('' !== $text) {
                return $this->truncate($text, 80);
            }
        }

        // If nothing found backward, also check the first run_started for initial messages.
        foreach ($sortedEvents as $event) {
            if (RunEventTypeEnum::RunStarted->value === $event->type) {
                $text = $this->extractInitialUserText($event);
                if ('' !== $text) {
                    return $this->truncate($text, 80);
                }
                break;
            }
        }

        // No user-visible text found in the event stream for this turn
        // (e.g. old/minimal streams with bare turn_advanced anchors only).
        return "Turn {$turnNo}";
    }

    /**
     * Extract user-visible text from a single event for title generation.
     *
     * Avoids raw system prompts. Prefers user input (steer/follow_up),
     * then assistant output, then initial user messages.
     */
    private function extractUserVisibleText(RunEvent $event): string
    {
        $payload = $event->payload;

        // agent_command_applied with steer / follow_up / append_message: user input
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

        // llm_step_completed: assistant output
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

    /**
     * Extract the first user message text from a run_started event.
     */
    private function extractInitialUserText(RunEvent $event): string
    {
        $innerPayload = \is_array($event->payload['payload'] ?? null) ? $event->payload['payload'] : [];
        $messages = \is_array($innerPayload['messages'] ?? null) ? $innerPayload['messages'] : [];

        foreach ($messages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? '');
            if ('user' !== $role) {
                continue;
            }
            $text = $this->extractTextFromContent($msg['content'] ?? []);
            if ('' !== $text) {
                return $text;
            }
        }

        // Fallback: check top-level messages (old format)
        $topMessages = \is_array($event->payload['messages'] ?? null) ? $event->payload['messages'] : [];
        foreach ($topMessages as $msg) {
            if (!\is_array($msg)) {
                continue;
            }
            $role = (string) ($msg['role'] ?? '');
            if ('user' !== $role) {
                continue;
            }
            $text = $this->extractTextFromContent($msg['content'] ?? []);
            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    /**
     * Extract text from a serialized message payload's content array.
     *
     * @param mixed $messagePayload The 'message' key from agent_command_applied payload
     */
    private function extractTextFromMessagePayload(mixed $messagePayload): string
    {
        if (!\is_array($messagePayload)) {
            return '';
        }

        return $this->extractTextFromContent($messagePayload['content'] ?? []);
    }

    /**
     * Extract text from a content array (list of typed content blocks).
     *
     * @param array<int, array<string, mixed>>|mixed $content
     */
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

    /**
     * Extract assistant text from a normalized assistant_message payload.
     */
    private function extractAssistantText(mixed $assistantMessage): string
    {
        return \is_array($assistantMessage)
            ? $this->extractTextFromContent($assistantMessage['content'] ?? null)
            : '';
    }

    /**
     * Truncate a string to a maximum length using Symfony String
     * (grapheme-safe), appending a Unicode ellipsis when truncated.
     */
    private function truncate(string $text, int $maxLen): string
    {
        return u($text)->truncate($maxLen, '…')->toString();
    }

    /**
     * Sort events ascending by sequence number.
     *
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
