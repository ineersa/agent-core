<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Symfony\Component\Yaml\Yaml;

/**
 * Tool-exchange pairing/suppression policy and shared tool-result presentation facts.
 *
 * Owns which ToolResult pairs with a ToolCall, which tool cards are suppressed
 * (ask_human HITL, standalone results consumed by an exchange, empty assistant
 * placeholder before a Question), and the shared "full render"/body-text facts
 * used by both candidate scoring and rendering. Rendering itself stays in
 * {@see TranscriptBlockWidgetFactory}; projection ownership of indexes, stable
 * keys, and full reprojection stays in {@see TranscriptVisualProjector}.
 */
final readonly class TranscriptToolPresentationPolicy
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer,
    ) {
    }

    public function isTranscriptWidgetSuppressed(TranscriptBlock $block): bool
    {
        return $this->shouldSuppressTranscriptWidget($block);
    }

    /**
     * @param array<string, list<TranscriptBlock>> $toolResultsByCallId
     * @param array<string, true>                  $consumedToolResultIds
     * @param array<string, true>                  $consumedToolCallIds
     */
    public function findCombinableToolResultForCall(
        TranscriptBlock $callBlock,
        array $toolResultsByCallId,
        array $consumedToolResultIds,
        array $consumedToolCallIds,
    ): ?TranscriptBlock {
        if (TranscriptBlockKindEnum::ToolCall !== $callBlock->kind) {
            return null;
        }

        if ($this->shouldSuppressTranscriptWidget($callBlock)) {
            return null;
        }

        $callId = $callBlock->meta['tool_call_id'] ?? null;
        if (!\is_string($callId) || '' === $callId || isset($consumedToolCallIds[$callId])) {
            return null;
        }

        $candidates = $toolResultsByCallId[$callId] ?? [];
        if ([] === $candidates) {
            return null;
        }

        $result = $this->selectBestToolResultForExchange($callBlock, $candidates, $consumedToolResultIds);
        if (null === $result) {
            return null;
        }

        if ($this->shouldSuppressTranscriptWidget($result)) {
            return null;
        }

        if ($this->subagentRenderer->supports($result)) {
            return null;
        }

        if (!$this->toolNamesCompatibleForExchange($callBlock, $result)) {
            return null;
        }

        return $result;
    }

    /**
     * @param array<string, true> $consumedToolCallIds
     */
    public function shouldSkipStandaloneToolResultInList(
        TranscriptBlock $block,
        array $consumedToolCallIds,
    ): bool {
        if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
            return false;
        }

        if ($this->shouldSuppressTranscriptWidget($block)) {
            return false;
        }

        if ($this->subagentRenderer->supports($block)) {
            return false;
        }

        $callId = $block->meta['tool_call_id'] ?? null;
        if (!\is_string($callId) || '' === $callId) {
            return false;
        }

        return isset($consumedToolCallIds[$callId]);
    }

    /**
     * @param array<string, true> $consumedToolResultIds
     * @param array<string, true> $consumedToolCallIds
     */
    public function markToolResultConsumedForExchange(
        TranscriptBlock $resultBlock,
        array &$consumedToolResultIds,
        array &$consumedToolCallIds,
    ): void {
        $consumedToolResultIds[$resultBlock->id] = true;

        $callId = $resultBlock->meta['tool_call_id'] ?? null;
        if (\is_string($callId) && '' !== $callId) {
            $consumedToolCallIds[$callId] = true;
        }
    }

    /**
     * ask_human often leaves an empty assistant markdown placeholder immediately before the Question block.
     */
    public function shouldSuppressEmptyAssistantPlaceholder(TranscriptBlock $block, ?TranscriptBlock $nextBlock): bool
    {
        if (TranscriptBlockKindEnum::AssistantMessage !== $block->kind) {
            return false;
        }

        if ('' !== $block->text) {
            return false;
        }

        return null !== $nextBlock && TranscriptBlockKindEnum::Question === $nextBlock->kind;
    }

    /**
     * Error, cancelled, and timed_out tool results bypass preview so diagnostics are not hidden.
     *
     * Projection currently sets is_error for cancelled/timed_out as well; color still keys off is_error when full.
     */
    public function toolResultIsFullRender(TranscriptBlock $block): bool
    {
        return $this->metaIsTruthy($block->meta['is_error'] ?? false)
            || $this->metaIsTruthy($block->meta['cancelled'] ?? false)
            || $this->metaIsTruthy($block->meta['timed_out'] ?? false);
    }

    public function toolResultBodyText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;
        if (\is_string($result) && '' !== $result) {
            return $this->compactSuccessfulEditWriteResultBody($block, $result);
        }
        if (\is_scalar($result) && '' !== (string) $result) {
            return (string) $result;
        }
        if (\is_array($result) || \is_object($result)) {
            return trim(Yaml::dump($result, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        $text = $block->text;
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName && $text === $toolName) {
            return '';
        }
        if ('Tool result' === $text) {
            return '';
        }

        return $text;
    }

    public function metaIsTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    /**
     * @param list<TranscriptBlock> $candidates
     * @param array<string, true>   $consumedToolResultIds
     */
    private function selectBestToolResultForExchange(
        TranscriptBlock $callBlock,
        array $candidates,
        array $consumedToolResultIds,
    ): ?TranscriptBlock {
        $best = null;
        $bestScore = \PHP_INT_MIN;

        foreach ($candidates as $candidate) {
            if (isset($consumedToolResultIds[$candidate->id])) {
                continue;
            }

            if (!$this->toolNamesCompatibleForExchange($callBlock, $candidate)) {
                continue;
            }

            $score = $this->toolResultExchangeCandidateScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function toolResultExchangeCandidateScore(TranscriptBlock $resultBlock): int
    {
        $score = 0;

        if ($this->toolResultIsFullRender($resultBlock)) {
            $score += 1000;
        }

        $body = $this->toolResultBodyText($resultBlock);
        if ('' !== trim($body)) {
            $score += 500 + min(\strlen($body), 200);
        }

        if ($resultBlock->streaming) {
            $score -= 50;
        }

        $score += $resultBlock->seq;

        return $score;
    }

    private function toolNamesCompatibleForExchange(TranscriptBlock $callBlock, TranscriptBlock $resultBlock): bool
    {
        $callName = $callBlock->meta['tool_name'] ?? null;
        $resultName = $resultBlock->meta['tool_name'] ?? null;
        if (!\is_string($callName) || '' === $callName || !\is_string($resultName) || '' === $resultName) {
            return true;
        }

        return $callName === $resultName;
    }

    /**
     * ask_human HITL: Question block is the authoritative transcript record; hide duplicate tool cards.
     *
     * Projection typically emits ToolCall/ToolResult before the Question block in the same poll batch;
     * a one-tick gap with only suppressed cards is acceptable and preferable to flashing raw payloads.
     */
    private function shouldSuppressTranscriptWidget(TranscriptBlock $block): bool
    {
        if (TranscriptBlockKindEnum::ToolCall === $block->kind && $this->isAskHumanToolName($block->meta['tool_name'] ?? null)) {
            return true;
        }

        if (TranscriptBlockKindEnum::ToolResult === $block->kind
            && $this->isAskHumanToolName($block->meta['tool_name'] ?? null)
            && !$this->toolResultIsFullRender($block)) {
            return true;
        }

        return false;
    }

    private function isAskHumanToolName(mixed $toolName): bool
    {
        return \is_string($toolName) && 'ask_human' === $toolName;
    }

    private function compactSuccessfulEditWriteResultBody(TranscriptBlock $block, string $result): string
    {
        if ($this->toolResultIsFullRender($block)) {
            return $result;
        }

        $toolName = $block->meta['tool_name'] ?? null;
        if (!\is_string($toolName) || 'edit' !== $toolName) {
            // write (and other) successful tool results are already compact status lines.
            return $result;
        }

        $marker = 'Updated file context:';
        $pos = strpos($result, $marker);
        if (false !== $pos) {
            return rtrim(substr($result, 0, $pos));
        }

        return $result;
    }
}
