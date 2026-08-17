<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Semantic mounted node for HITL question transcript records.
 */
final class QuestionTranscriptWidget extends ContainerWidget implements MutableTranscriptWidget
{
    private ?TranscriptVisualNode $node = null;

    private ?AbstractWidget $content = null;

    public function __construct(
        private readonly TranscriptBlockWidgetFactory $factory,
        private readonly TuiTheme $theme,
    ) {
    }

    public function canBind(TranscriptVisualNode $node): bool
    {
        return TranscriptVisualNode::KIND_QUESTION === $node->kind;
    }

    public function node(): ?TranscriptVisualNode
    {
        return $this->node;
    }

    public function content(): ?AbstractWidget
    {
        return $this->content;
    }

    public function apply(TranscriptVisualNode $node): void
    {
        if (TranscriptVisualNode::KIND_QUESTION !== $node->kind || null === $node->primary) {
            throw new \LogicException('QuestionTranscriptWidget requires a question visual node.');
        }

        $previous = $this->node;
        if (null !== $previous && $previous->sameSources($node) && null !== $this->content) {
            return;
        }

        $this->setContent($this->factory->buildWidget($node->primary, $this->theme));
        $this->node = $node;
    }

    private function setContent(AbstractWidget $content): void
    {
        if ($this->content === $content) {
            return;
        }
        $this->clear();
        $this->content = $content;
        $this->add($content);
    }
}
