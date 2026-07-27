<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Stable outer transcript child that owns one visual node's source dependencies.
 *
 * Keeps ContainerWidget identity/order while mutating or replacing the inner
 * native Symfony subtree (MarkdownWidget, tool exchange, question, subagent, …).
 * Streaming markdown preserves MarkdownWidget instance via setText/setStyle.
 */
final class SemanticTranscriptNodeWidget extends ContainerWidget
{
    private ?TranscriptVisualNode $node = null;

    private ?AbstractWidget $content = null;

    public function __construct(
        private readonly TranscriptBlockWidgetFactory $factory,
        private readonly TuiTheme $theme,
    ) {
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
        $previous = $this->node;
        if (null !== $previous && $previous->sameSources($node) && null !== $this->content) {
            return;
        }

        if (
            null !== $previous
            && TranscriptVisualNode::KIND_MARKDOWN === $node->kind
            && TranscriptVisualNode::KIND_MARKDOWN === $previous->kind
            && $this->content instanceof MarkdownWidget
            && null !== $node->primary
        ) {
            $this->updateMarkdown($this->content, $node);
            $this->node = $node;

            return;
        }

        $this->setContent($this->buildContent($node));
        $this->node = $node;
    }

    private function buildContent(TranscriptVisualNode $node): AbstractWidget
    {
        return match ($node->kind) {
            TranscriptVisualNode::KIND_WELCOME => new WelcomeTranscriptWidget($this->theme),
            TranscriptVisualNode::KIND_SEPARATOR => new TurnSeparatorWidget($this->theme),
            TranscriptVisualNode::KIND_TOOL_EXCHANGE => $this->factory->buildToolExchangeWidget(
                $node->primary ?? throw new \LogicException('Tool exchange missing call block.'),
                $node->secondary ?? throw new \LogicException('Tool exchange missing result block.'),
                $this->theme,
            ),
            default => $this->factory->buildWidget(
                $node->primary ?? throw new \LogicException('Visual node missing primary block.'),
                $this->theme,
            ),
        };
    }

    private function updateMarkdown(MarkdownWidget $widget, TranscriptVisualNode $node): void
    {
        $fresh = $this->factory->buildWidget(
            $node->primary ?? throw new \LogicException('Markdown node missing primary block.'),
            $this->theme,
        );
        if (!$fresh instanceof MarkdownWidget) {
            $this->setContent($fresh);

            return;
        }

        $widget->setText($fresh->getText());
        $widget->setStyle($fresh->getStyle());
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
