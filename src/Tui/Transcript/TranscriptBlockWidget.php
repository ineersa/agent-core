<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
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

        $allLines = [];
        $blockCount = \count($this->blocks);
        for ($index = 0; $index < $blockCount; ++$index) {
            $block = $this->blocks[$index];
            $nextBlock = $this->blocks[$index + 1] ?? null;
            if ($this->factory->isTranscriptWidgetSuppressed($block)) {
                continue;
            }
            if ($this->factory->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock)) {
                continue;
            }

            $cacheKey = $this->blockCacheKey(
                $block,
                $context,
                $themeFingerprint,
                $environmentFingerprint,
            );

            $cached = $this->blockRenderCache[$block->id] ?? null;
            if (null !== $cached && $cached['key'] === $cacheKey) {
                array_push($allLines, ...$cached['lines']);

                continue;
            }

            $lines = $this->renderSingleBlock($block, $context);
            $this->blockRenderCache[$block->id] = [
                'key' => $cacheKey,
                'lines' => $lines,
            ];
            array_push($allLines, ...$lines);
        }

        return $allLines;
    }

    /** @return list<string> */
    private function renderSingleBlock(TranscriptBlock $block, TuiRenderContext $context): array
    {
        $root = new ContainerWidget();
        $root->add($this->factory->buildWidget($block, $context->theme));

        return $this->widgetRenderer->render($root, $context);
    }

    private function blockCacheKey(
        TranscriptBlock $block,
        TuiRenderContext $context,
        string $themeFingerprint,
        string $environmentFingerprint,
    ): string {
        $meta = $block->meta;
        ksort($meta);

        return hash('xxh128', implode("\x1e", [
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
        ]));
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
}
