<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Transcript widget that renders {@see TranscriptBlock} DTOs using
 * the Symfony TUI widget-tree renderer.
 *
 * Internally builds one root {@see ContainerWidget} tree per render call
 * via {@see TranscriptBlockWidgetFactory} and renders it through
 * {@see SymfonyTuiWidgetRenderer}. This replaces the previous flat
 * loop-over-blocks approach — the old flat path is not retained.
 *
 * The public API ({@see setBlocks()}, {@see addBlock()}, {@see render()})
 * is unchanged so {@see ChatScreen} / {@see LiveTextWidget} integration
 * is unaffected.
 */
final class TranscriptBlockWidget implements TuiWidget
{
    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    /** Lazy-initialised factory wired with transcript display config. */
    private ?TranscriptBlockWidgetFactory $factory = null;

    public function __construct(
        private readonly SymfonyTuiWidgetRenderer $widgetRenderer = new SymfonyTuiWidgetRenderer(),
        private readonly TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
    ) {
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

        $root = $this->getFactory()->buildRoot($this->blocks, $context->theme);

        return $this->widgetRenderer->render($root, $context);
    }

    private function getFactory(): TranscriptBlockWidgetFactory
    {
        return $this->factory ??= new TranscriptBlockWidgetFactory(
            displayConfig: $this->displayConfig,
        );
    }
}
