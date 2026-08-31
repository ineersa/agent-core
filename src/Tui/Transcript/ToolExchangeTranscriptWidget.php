<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Semantic mounted node for tool call ↔ result exchange identity.
 *
 * Stable key is {@code exchange:<tool_call_id>} from pending call through result.
 * Owns source dependencies; rebuilds the native card subtree when sources change.
 */
final class ToolExchangeTranscriptWidget extends ContainerWidget implements MutableTranscriptWidget
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
        return TranscriptVisualNode::KIND_TOOL_EXCHANGE === $node->kind;
    }

    
    
    public function apply(TranscriptVisualNode $node): void
    {
        if (TranscriptVisualNode::KIND_TOOL_EXCHANGE !== $node->kind || null === $node->primary) {
            throw new \LogicException('ToolExchangeTranscriptWidget requires a tool-exchange visual node.');
        }

        $previous = $this->node;
        if (null !== $previous && $previous->sameSources($node) && null !== $this->content) {
            return;
        }

        $content = null !== $node->secondary
            ? $this->factory->buildToolExchangeWidget($node->primary, $node->secondary, $this->theme)
            : $this->factory->buildWidget($node->primary, $this->theme);

        $this->setContent($content);
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
