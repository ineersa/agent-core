<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Semantic mounted node for streaming/final message markdown.
 *
 * Keeps a stable outer identity and mutates the inner MarkdownWidget in place
 * across ordinary token updates. Symfony owns render caching.
 */
final class StreamingMarkdownTranscriptWidget extends ContainerWidget implements MutableTranscriptWidget
{
    private ?TranscriptVisualNode $node = null;

    private ?MarkdownWidget $markdown = null;

    public function __construct(
        private readonly TranscriptBlockWidgetFactory $factory,
        private readonly TuiTheme $theme,
    ) {
    }

    public function canBind(TranscriptVisualNode $node): bool
    {
        return TranscriptVisualNode::KIND_MARKDOWN === $node->kind;
    }

    public function node(): ?TranscriptVisualNode
    {
        return $this->node;
    }

    public function markdown(): ?MarkdownWidget
    {
        return $this->markdown;
    }

    public function apply(TranscriptVisualNode $node): void
    {
        if (TranscriptVisualNode::KIND_MARKDOWN !== $node->kind || null === $node->primary) {
            throw new \LogicException('StreamingMarkdownTranscriptWidget requires a markdown visual node.');
        }

        $previous = $this->node;
        if (null !== $previous && $previous->sameSources($node) && null !== $this->markdown) {
            return;
        }

        $fresh = $this->factory->buildWidget($node->primary, $this->theme);
        if (!$fresh instanceof MarkdownWidget) {
            // Hidden-thinking TextWidget path, etc. — replace inner content once.
            $this->clear();
            $this->markdown = null;
            $this->add($fresh);
            $this->node = $node;

            return;
        }

        if (null !== $this->markdown) {
            $this->markdown->setText($fresh->getText());
            $this->markdown->setStyle($fresh->getStyle());
            $this->node = $node;

            return;
        }

        $this->clear();
        $this->markdown = $fresh;
        $this->add($fresh);
        $this->node = $node;
    }
}
