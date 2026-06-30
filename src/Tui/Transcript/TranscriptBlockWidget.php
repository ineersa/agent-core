<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Renders {@see TranscriptBlock} DTOs through the Symfony TUI widget-tree pipeline.
 *
 * Each {@see render()} builds one root {@see \Symfony\Component\Tui\Widget\ContainerWidget}
 * via {@see TranscriptBlockWidgetFactory} and {@see SymfonyTuiWidgetRenderer}. This replaced
 * the old flat loop-over-blocks renderer; no alternate flat path is retained.
 *
 * {@see setBlocks()}, {@see addBlock()}, and {@see render()} stay stable for ChatScreen /
 * LiveTextWidget integration.
 *
 * Receives {@see TranscriptDisplayConfig} and mutable {@see TranscriptDisplayState} so renderers
 * can apply local display policy (e.g. preview expansion) without encoding collapse in projection
 * blocks or {@see TranscriptBlock::$collapsed}.
 */
final class TranscriptBlockWidget implements TuiWidget
{
    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    private readonly TranscriptBlockWidgetFactory $factory;

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

        $root = $this->factory->buildRoot($this->blocks, $context->theme);

        return $this->widgetRenderer->render($root, $context);
    }
}
