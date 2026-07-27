<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * Canonical transcript blocks → typed visual nodes (presentation policy).
 *
 * Owns ordering, tool exchange pairing, suppressions, separators, and welcome.
 * Does not build widgets or hash block text/meta — dirty detection is object
 * identity + {@see TranscriptDisplayState} presentation revision.
 */
final class TranscriptVisualProjector
{
    private const string WELCOME_KEY = '__welcome__';

    private readonly TranscriptBlockWidgetFactory $factory;

    public function __construct(
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        ?TranscriptBlockWidgetFactory $factory = null,
    ) {
        $this->factory = $factory ?? new TranscriptBlockWidgetFactory(
            subagentRenderer: new SubagentResultRenderer(
                displayConfig: $displayConfig,
                displayState: $displayState,
            ),
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
    }

    public function factory(): TranscriptBlockWidgetFactory
    {
        return $this->factory;
    }

    public function presentationRevision(): int
    {
        // Theme/config are immutable for the screen lifetime; only preview expansion is live.
        return $this->factory->displayState()->previewableBlocksExpanded ? 1 : 0;
    }

    /**
     * @param list<TranscriptBlock> $blocks
     *
     * @return list<TranscriptVisualNode>
     */
    public function project(array $blocks): array
    {
        $revision = $this->presentationRevision();

        if ([] === $blocks) {
            return [$this->welcomeNode($revision)];
        }

        $toolResultsByCallId = $this->indexToolResultsByCallId($blocks);
        $consumedToolResultIds = [];
        $consumedToolCallIds = [];
        $items = [];
        $hasRenderedVisibleBlock = false;

        $blockCount = \count($blocks);
        for ($index = 0; $index < $blockCount; ++$index) {
            $block = $blocks[$index];
            $nextBlock = $blocks[$index + 1] ?? null;

            if ($this->factory->isTranscriptWidgetSuppressed($block)) {
                continue;
            }
            if ($this->factory->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock)) {
                continue;
            }
            if (TranscriptBlockKindEnum::ToolResult === $block->kind
                && $this->factory->shouldSkipStandaloneToolResultInList($block, $consumedToolCallIds)) {
                continue;
            }

            if ($hasRenderedVisibleBlock && TranscriptBlockKindEnum::UserMessage === $block->kind) {
                $items[] = new TranscriptVisualNode(
                    key: 'sep-before:'.$block->id,
                    kind: TranscriptVisualNode::KIND_SEPARATOR,
                    primary: null,
                    secondary: null,
                    presentationRevision: $revision,
                );
            }

            $matchedToolResult = null;
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $matchedToolResult = $this->factory->findCombinableToolResultForCall(
                    $block,
                    $toolResultsByCallId,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
            }

            if (null !== $matchedToolResult) {
                $this->factory->markToolResultConsumedForExchange(
                    $matchedToolResult,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
                $items[] = new TranscriptVisualNode(
                    key: $this->stableToolVisualKey($block),
                    kind: TranscriptVisualNode::KIND_TOOL_EXCHANGE,
                    primary: $block,
                    secondary: $matchedToolResult,
                    presentationRevision: $revision,
                );
            } elseif (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                // Pending tool call uses the same stable key as the eventual exchange so
                // result arrival becomes an in-wrapper content replace, not remove/reinsert.
                $items[] = new TranscriptVisualNode(
                    key: $this->stableToolVisualKey($block),
                    kind: $this->classifyStandalone($block),
                    primary: $block,
                    secondary: null,
                    presentationRevision: $revision,
                );
            } else {
                $items[] = new TranscriptVisualNode(
                    key: $block->id,
                    kind: $this->classifyStandalone($block),
                    primary: $block,
                    secondary: null,
                    presentationRevision: $revision,
                );
            }

            $hasRenderedVisibleBlock = true;
        }

        if ([] === $items) {
            return [$this->welcomeNode($revision)];
        }

        return $items;
    }

    private function welcomeNode(int $revision): TranscriptVisualNode
    {
        return new TranscriptVisualNode(
            key: self::WELCOME_KEY,
            kind: TranscriptVisualNode::KIND_WELCOME,
            primary: null,
            secondary: null,
            presentationRevision: $revision,
        );
    }

    private function classifyStandalone(TranscriptBlock $block): string
    {
        if ($this->factory->subagentRenderer()->supports($block)) {
            return TranscriptVisualNode::KIND_SUBAGENT;
        }

        if (TranscriptBlockKindEnum::Question === $block->kind) {
            return TranscriptVisualNode::KIND_QUESTION;
        }

        if ($this->isMarkdownVisual($block)) {
            return TranscriptVisualNode::KIND_MARKDOWN;
        }

        return TranscriptVisualNode::KIND_GENERIC;
    }

    /**
     * Stable presentation key for a tool call and its eventual paired exchange.
     *
     * Invariant: canonical projection writes one ToolCall block per non-empty
     * tool_call_id (`tool_call_<id>` via ToolProjectionSubscriber). Identity is
     * that id; block id is only a degenerate fallback if meta is empty.
     */
    private function stableToolVisualKey(TranscriptBlock $callBlock): string
    {
        $callId = $callBlock->meta['tool_call_id'] ?? null;
        if (\is_string($callId) && '' !== $callId) {
            return 'exchange:'.$callId;
        }

        return 'exchange:'.$callBlock->id;
    }

    private function isMarkdownVisual(TranscriptBlock $block): bool
    {
        if (\in_array($block->kind, [
            TranscriptBlockKindEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking,
        ], true)) {
            return true;
        }

        return TranscriptBlockKindEnum::System === $block->kind
            && 'markdown' === ($block->meta['style'] ?? null);
    }

    /**
     * @param list<TranscriptBlock> $blocks
     *
     * @return array<string, list<TranscriptBlock>>
     */
    private function indexToolResultsByCallId(array $blocks): array
    {
        $index = [];
        foreach ($blocks as $block) {
            if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
                continue;
            }
            $callId = $block->meta['tool_call_id'] ?? null;
            if (!\is_string($callId) || '' === $callId) {
                continue;
            }
            $index[$callId][] = $block;
        }

        return $index;
    }
}
