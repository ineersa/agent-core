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
     * @param ContainerWidget  $root    Root widget tree (typically a ContainerWidget
     *                                  containing one TextWidget per transcript block)
     * @param TuiRenderContext $context Project-native render context with terminal dimensions
     *
     * @return list<string> ANSI-styled output lines
     */
    public function render(ContainerWidget $root, TuiRenderContext $context): array
    {
        $columns = max($context->terminalWidth, 1);
        $rows = max($context->terminalHeight, 1);

        return $this->renderer->render($root, $columns, $rows);
    }
}
