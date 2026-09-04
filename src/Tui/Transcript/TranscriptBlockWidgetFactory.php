<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Orchestrates transcript block routing for the transcript widget tree.
 *
 * Structured subagent results are delegated to {@see SubagentResultRenderer};
 * tool pairing/suppression to {@see TranscriptToolPresentationPolicy} and result-body
 * presentation facts to {@see TranscriptToolResultFacts}; tool-call/result/exchange
 * rendering to {@see TranscriptToolRenderer}; remaining kinds (markdown/thinking,
 * question, system, flat fallback) to {@see TranscriptBlockRenderer}.
 */
final readonly class TranscriptBlockWidgetFactory
{
    private readonly TranscriptToolPresentationPolicy $toolPresentationPolicy;

    private readonly TranscriptToolRenderer $toolRenderer;

    private readonly TranscriptBlockRenderer $blockRenderer;

    private readonly TranscriptToolResultFacts $toolResultFacts;

    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer = new SubagentResultRenderer(),
        private readonly TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        private readonly TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        private readonly EditToolCallDiffRenderer $editDiffRenderer = new EditToolCallDiffRenderer(),
        private readonly WriteToolCallContentRenderer $writeContentRenderer = new WriteToolCallContentRenderer(),
        private readonly TranscriptLinePreviewService $linePreviewService = new TranscriptLinePreviewService(),
        private readonly ToolArgumentColoredFormatter $toolArgumentColoredFormatter = new ToolArgumentColoredFormatter(),
        private readonly ViewImageTranscriptFormatter $viewImageFormatter = new ViewImageTranscriptFormatter(),
    ) {
        $this->toolResultFacts = new TranscriptToolResultFacts();
        $this->toolPresentationPolicy = new TranscriptToolPresentationPolicy($this->subagentRenderer, $this->toolResultFacts);
        $this->toolRenderer = new TranscriptToolRenderer(
            $this->displayConfig,
            $this->displayState,
            $this->editDiffRenderer,
            $this->writeContentRenderer,
            $this->linePreviewService,
            $this->toolArgumentColoredFormatter,
            $this->viewImageFormatter,
            $this->toolResultFacts,
            new TranscriptToolCollapsedPresentation(),
        );
        $this->blockRenderer = new TranscriptBlockRenderer($this->displayConfig);
    }

    public function displayState(): TranscriptDisplayState
    {
        return $this->displayState;
    }

    public function subagentRenderer(): SubagentResultRenderer
    {
        return $this->subagentRenderer;
    }

    public function isTranscriptWidgetSuppressed(TranscriptBlock $block): bool
    {
        return $this->toolPresentationPolicy->isTranscriptWidgetSuppressed($block);
    }

    /**
     * Build a single widget for one transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        // Structured subagent result blocks stay on the dedicated renderer before generic ToolResult cards.
        if ($this->subagentRenderer->supports($block)) {
            return $this->subagentRenderer->buildWidget($block, $theme);
        }

        // ask_human HITL: Question block is authoritative; suppress duplicate tool cards (single-block render path).
        if ($this->isTranscriptWidgetSuppressed($block)) {
            return new TextWidget('');
        }

        // RENDER-04: ToolCall → compact card (glyph header, YAML-like args, arg preview).
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            return $this->toolRenderer->buildToolCallWidget($block, $theme);
        }

        // RENDER-04: normal ToolResult → compact card (header, body preview unless error/cancel/timeout).
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            return $this->toolRenderer->buildToolResultWidget($block, $theme);
        }

        // All remaining kinds (markdown/thinking, question, system, flat fallback) → block renderer.
        return $this->blockRenderer->buildWidget($block, $theme);
    }

    /**
     * Visual transcript collapse: render ToolCall + matching ToolResult as one compact card.
     *
     * Canonical projection still stores separate blocks; list assembly in
     * {@see TranscriptMountedWidget}
     * pairs by tool_call_id and skips the standalone ToolResult row when consumed here.
     */
    public function buildToolExchangeWidget(TranscriptBlock $callBlock, TranscriptBlock $resultBlock, TuiTheme $theme): AbstractWidget
    {
        if ($this->subagentRenderer->supports($resultBlock)) {
            return $this->buildWidget($callBlock, $theme);
        }

        return $this->toolRenderer->buildToolExchangeWidget($callBlock, $resultBlock, $theme);
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
        return $this->toolPresentationPolicy->findCombinableToolResultForCall(
            $callBlock,
            $toolResultsByCallId,
            $consumedToolResultIds,
            $consumedToolCallIds,
        );
    }

    /**
     * @param array<string, true> $consumedToolCallIds
     */
    public function shouldSkipStandaloneToolResultInList(
        TranscriptBlock $block,
        array $consumedToolCallIds,
    ): bool {
        return $this->toolPresentationPolicy->shouldSkipStandaloneToolResultInList($block, $consumedToolCallIds);
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
        $this->toolPresentationPolicy->markToolResultConsumedForExchange(
            $resultBlock,
            $consumedToolResultIds,
            $consumedToolCallIds,
        );
    }

    /**
     * ask_human often leaves an empty assistant markdown placeholder immediately before the Question block.
     */
    public function shouldSuppressEmptyAssistantPlaceholder(TranscriptBlock $block, ?TranscriptBlock $nextBlock): bool
    {
        return $this->toolPresentationPolicy->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock);
    }
}
