<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Translates a single {@see TranscriptBlock} into ANSI-styled, word-wrapped output lines.
 *
 * This is a pure renderer: it has no state, no layout opinions, and does not
 * implement {@see TuiWidget}. Widgets compose it for the display loop.
 *
 * Internally delegates to the Symfony TUI widget-tree path via
 * {@see TranscriptBlockWidgetFactory} and {@see SymfonyTuiWidgetRenderer}.
 * The old flat TextWrapper path has been replaced — there is no fallback.
 *
 * Structured subagent result blocks are rendered through
 * {@see SubagentResultRenderer::buildContent()}.
 */
final readonly class TranscriptBlockRenderer
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentResultRenderer = new SubagentResultRenderer(),
        private readonly SymfonyTuiWidgetRenderer $widgetRenderer = new SymfonyTuiWidgetRenderer(),
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
        $factory = new TranscriptBlockWidgetFactory($this->subagentResultRenderer);

        $root = new ContainerWidget();
        $root->add($factory->buildWidget($block, $context->theme));

        return $this->widgetRenderer->render($root, $context);
    }

    /* ───────── Public helpers (preserved for tests / external callers) ───────── */

    public function getSubagentRenderer(): SubagentResultRenderer
    {
        return $this->subagentResultRenderer;
    }
}
