<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Per-block rendering facade that delegates to the Symfony TUI widget-tree pipeline.
 *
 * This class exposes a convenience {@see self::renderBlock()} method for rendering
 * a single {@see TranscriptBlock} through the project's Symfony TUI widget-tree
 * path ({@see TranscriptBlockWidgetFactory} + {@see SymfonyTuiWidgetRenderer}).
 *
 * It does NOT implement {@see TuiWidget} and is NOT used by the ChatScreen display
 * loop — {@see TranscriptBlockWidget} uses the factory and renderer directly for the
 * full block list. This class exists as a convenience for tests, external callers, and
 * any code path that needs to render one block at a time without creating a widget.
 *
 * Internally delegates to:
 * - {@see TranscriptBlockWidgetFactory} for glyph/color/display-text logic
 * - {@see SymfonyTuiWidgetRenderer} for ANSI-safe widget wrapping via Symfony Renderer
 * - {@see SubagentResultRenderer::buildContent()} for structured subagent result blocks
 *
 * The old flat TextWrapper path has been replaced — there is no fallback.
 */
final readonly class TranscriptBlockRenderer
{
    public function __construct(
        private readonly SymfonyTuiWidgetRenderer $widgetRenderer = new SymfonyTuiWidgetRenderer(),
        private readonly TranscriptBlockWidgetFactory $factory = new TranscriptBlockWidgetFactory(),
    ) {
    }

    /**
     * Render a single transcript block into styled, wrapped output lines.
     *
     * @param TuiRenderContext $context Terminal width and active theme
     *
     * @return list<string> One or more display lines
     */
    public function renderBlock(TranscriptBlock $block, TuiRenderContext $context): array
    {
        $root = new ContainerWidget();
        $root->add($this->factory->buildWidget($block, $context->theme));

        return $this->widgetRenderer->render($root, $context);
    }
}
