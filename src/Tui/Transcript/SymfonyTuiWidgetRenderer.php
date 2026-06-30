<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Adapter that renders a Symfony TUI {@see ContainerWidget} root tree through
 * the Symfony {@see Renderer} and returns plain/ANSI string lines.
 *
 * This is the single integration point between the project-native
 * {@see TuiRenderContext} and the Symfony TUI rendering engine. All transcript
 * block rendering flows through this class — there is no alternate flat path.
 *
 * The Symfony Renderer handles:
 *  - widget layout (vertical/horizontal)
 *  - style resolution (padding, border, color)
 *  - chrome application
 *  - ANSI-safe text wrapping via TextWidget
 *
 * For the transcript use case we pass bare TextWidget children inside a
 * root ContainerWidget with no additional chrome, relying on TextWidget's
 * built-in wrapping for the charismatic flat-transcript visual style.
 */
final readonly class SymfonyTuiWidgetRenderer
{
    public function __construct(
        private readonly Renderer $renderer = new Renderer(),
    ) {
    }

    /**
     * Render a widget tree and return the output lines.
     *
     * On first invocation, installs the active theme's markdown element
     * colours into the {@see MarkdownWidget} sub-element stylesheet so
     * heading, link, code, quote, list-bullet, HR, and code-block-border
     * tokens use Hatfield theme colours instead of Symfony TUI defaults.
     *
     * @param ContainerWidget  $root    Root widget tree (typically a ContainerWidget
     *                                  containing one widget per transcript block —
     *                                  TextWidget for flat blocks, MarkdownWidget for
     *                                  user/assistant/thinking blocks)
     * @param TuiRenderContext $context Project-native render context with terminal dimensions
     *
     * @return list<string> ANSI-styled output lines
     */
    public function render(ContainerWidget $root, TuiRenderContext $context): array
    {
        MarkdownThemeStyleSheet::apply($context->theme);

        $columns = max($context->terminalWidth, 1);
        $rows = max($context->terminalHeight, 1);

        return $this->renderer->render($root, $columns, $rows);
    }
}
