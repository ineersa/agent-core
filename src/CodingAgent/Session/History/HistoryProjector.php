<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

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
    public function __construct(
        private readonly EventInspectionSummarizer $eventInspectionSummarizer = new EventInspectionSummarizer(),
    ) {
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

        $builder = $this->createStreamBuilder();
        foreach ($sorted as $event) {
            $builder->apply($event);
        }

        return $builder->finish();
    }

    public function createStreamBuilder(): HistoryStreamBuilder
    {
        return new HistoryStreamBuilder($this->eventInspectionSummarizer);
    }

    public function eventInspectionSummarizer(): EventInspectionSummarizer
    {
        return $this->eventInspectionSummarizer;
    }

    /**
     * @internal used by {@see HistoryStreamBuilder}
     */
    public static function extractInitialUserText(RunEvent $event): string
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

    /**
     * @internal used by {@see HistoryStreamBuilder}
     */
    public static function extractTextFromContent(mixed $content): string
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
