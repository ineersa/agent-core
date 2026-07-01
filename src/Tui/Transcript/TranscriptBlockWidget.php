<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Renders {@see TranscriptBlock} DTOs through the Symfony TUI widget-tree pipeline.
 *
 * Each visible block is rendered through {@see TranscriptBlockWidgetFactory} and
 * {@see SymfonyTuiWidgetRenderer}. Unchanged finalized blocks reuse a local
 * per-block line cache so long transcripts do not rebuild every widget tree on
 * every render tick.
 *
 * {@see setBlocks()}, {@see addBlock()}, and {@see render()} stay stable for ChatScreen /
 * LiveTextWidget integration.
 */
final class TranscriptBlockWidget implements TuiWidget
{
    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    private readonly TranscriptBlockWidgetFactory $factory;

    /**
     * @var array<string, array{key: string, lines: list<string>}>
     */
    private array $blockRenderCache = [];

    public function __construct(
        private readonly SymfonyTuiWidgetRenderer $widgetRenderer = new SymfonyTuiWidgetRenderer(),
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
        $this->factory = new TranscriptBlockWidgetFactory(
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
    }

    /** @return list<TranscriptBlock> */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /** @param list<TranscriptBlock> $blocks */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = $blocks;
        $this->pruneBlockRenderCache();
    }

    public function addBlock(TranscriptBlock $block): void
    {
        $this->blocks[] = $block;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if ([] === $this->blocks) {
            return [$context->theme->muted('  Welcome to Agent Core. Type a message below to start.')];
        }

        $themeFingerprint = $this->themeFingerprint($context->theme);
        $environmentFingerprint = $this->renderEnvironmentFingerprint();

        $toolResultsByCallId = $this->indexToolResultsByCallId($this->blocks);
        $consumedToolResultIds = [];
        $consumedToolCallIds = [];

        $allLines = [];
        $blockCount = \count($this->blocks);
        $hasRenderedVisibleBlock = false;
        for ($index = 0; $index < $blockCount; ++$index) {
            $block = $this->blocks[$index];
            $nextBlock = $this->blocks[$index + 1] ?? null;
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

            if ($this->shouldInsertTurnSeparatorBefore($block, $hasRenderedVisibleBlock)) {
                $allLines[] = $this->renderTurnSeparatorLine($context);
            }

            $matchedToolResult = null;
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $matchedToolResult = $this->factory->findCombinableToolResultForCall($block, $toolResultsByCallId, $consumedToolResultIds, $consumedToolCallIds);
            }

            $cacheKey = $this->blockCacheKey(
                $block,
                $context,
                $themeFingerprint,
                $environmentFingerprint,
                $matchedToolResult,
            );

            $cached = $this->blockRenderCache[$block->id] ?? null;
            if (null !== $cached && $cached['key'] === $cacheKey) {
                if (null !== $matchedToolResult) {
                    $this->factory->markToolResultConsumedForExchange($matchedToolResult, $consumedToolResultIds, $consumedToolCallIds);
                }
                array_push($allLines, ...$cached['lines']);
                $hasRenderedVisibleBlock = true;

                continue;
            }

            $lines = $this->renderSingleBlock($block, $context, $matchedToolResult);
            $this->blockRenderCache[$block->id] = [
                'key' => $cacheKey,
                'lines' => $lines,
            ];
            if (null !== $matchedToolResult) {
                $this->factory->markToolResultConsumedForExchange($matchedToolResult, $consumedToolResultIds, $consumedToolCallIds);
            }
            array_push($allLines, ...$lines);
            $hasRenderedVisibleBlock = true;
        }

        return $allLines;
    }

    /** @return list<string> */
    private function renderSingleBlock(TranscriptBlock $block, TuiRenderContext $context, ?TranscriptBlock $matchedToolResult = null): array
    {
        $root = new ContainerWidget();
        if (null !== $matchedToolResult) {
            $root->add($this->factory->buildToolExchangeWidget($block, $matchedToolResult, $context->theme));
        } else {
            $root->add($this->factory->buildWidget($block, $context->theme));
        }

        return $this->widgetRenderer->render($root, $context);
    }

    private function blockCacheKey(
        TranscriptBlock $block,
        TuiRenderContext $context,
        string $themeFingerprint,
        string $environmentFingerprint,
        ?TranscriptBlock $matchedToolResult = null,
    ): string {
        $meta = $block->meta;
        ksort($meta);

        $parts = [
            $block->id,
            $block->kind->value,
            (string) $block->seq,
            $block->text,
            serialize($meta),
            $block->streaming ? '1' : '0',
            (string) max($context->terminalWidth, 1),
            (string) max($context->terminalHeight, 1),
            $themeFingerprint,
            $environmentFingerprint,
        ];

        if (null !== $matchedToolResult) {
            $resultMeta = $matchedToolResult->meta;
            ksort($resultMeta);
            $parts[] = 'exchange:'.$matchedToolResult->id;
            $parts[] = (string) $matchedToolResult->seq;
            $parts[] = $matchedToolResult->text;
            $parts[] = serialize($resultMeta);
            $parts[] = $matchedToolResult->streaming ? '1' : '0';
        }

        return hash('xxh128', implode("\x1e", $parts));
    }

    private function themeFingerprint(TuiTheme $theme): string
    {
        $palette = $theme->getPalette();

        return hash('xxh128', $palette->name.serialize($palette->colors));
    }

    private function renderEnvironmentFingerprint(): string
    {
        $config = $this->factory->displayConfig();
        $state = $this->factory->displayState();

        return hash('xxh128', serialize([
            $config->thinkingVisible,
            $config->thinkingStyle,
            $config->previewsExpandedByDefault,
            $config->toolResultPreviewLines,
            $config->diffPreviewLines,
            $state->previewableBlocksExpanded,
        ]));
    }

    private function pruneBlockRenderCache(): void
    {
        $liveIds = [];
        foreach ($this->blocks as $block) {
            $liveIds[$block->id] = true;
        }

        foreach (array_keys($this->blockRenderCache) as $blockId) {
            if (!isset($liveIds[$blockId])) {
                unset($this->blockRenderCache[$blockId]);
            }
        }
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

    private function shouldInsertTurnSeparatorBefore(TranscriptBlock $block, bool $hasRenderedVisibleBlock): bool
    {
        if (!$hasRenderedVisibleBlock) {
            return false;
        }

        return TranscriptBlockKindEnum::UserMessage === $block->kind;
    }

    private function renderTurnSeparatorLine(TuiRenderContext $context): string
    {
        $width = max($context->terminalWidth, 1);

        return $context->theme->muted(str_repeat(TranscriptGlyphs::TURN_SEPARATOR_CHAR, $width));
    }
}
