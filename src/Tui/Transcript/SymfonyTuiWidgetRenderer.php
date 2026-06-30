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
 *
 * ## Markdown sub-element colours
 *
 * {@see MarkdownWidget} sub-elements (headings, code, links, list bullets,
 * blockquotes, HR, code-block borders) currently use their default Symfony
 * TUI colours:
 *
 * | Element | Default colour |
 * |---|---|
 * | heading | cyan |
 * | link | blue |
 * | link-url | gray |
 * | code | yellow |
 * | code-block-border | gray |
 * | quote | italic (no colour) |
 * | quote-border | gray |
 * | hr | gray |
 * | list-bullet | cyan |
 *
 * Applying Hatfield theme markdown tokens to these sub-elements requires
 * either upstream Symfony TUI API support for stylesheet injection in the
 * standalone Renderer path (widgets not attached to a WidgetContext), a
 * custom MarkdownWidget subclass, or a full Tui tree bootstrap. The
 * {@see AbstractWidget::resolveElement()} method is {@code final} and falls
 * back to a private static default stylesheet when the widget has no context,
 * so sub-element styling cannot be customised through the current public API.
 *
 * Block-level colours (user, assistant, thinking) still use Hatfield theme
 * tokens via the widget instance style set in
 * {@see TranscriptBlockWidgetFactory::buildMarkdownWidget()}.
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
     *                                  containing one widget per transcript block
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
