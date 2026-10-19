<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Semantic mounted node for a single tool card or call ↔ result exchange.
 *
 * Call/exchange key is {@code exchange:<tool_call_id>} from pending through result;
 * standalone results keep their block key.
 * Retains current sources; rebuilds the native card only when rendered inputs change.
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
        if (null !== $previous && null !== $this->content
            && $previous->presentationRevision === $node->presentationRevision
            && $this->sameRenderedSource($previous->primary, $node->primary)
            && $this->sameRenderedSource($previous->secondary, $node->secondary)) {
            $this->node = $node;

            return;
        }

        $content = null !== $node->secondary
            ? $this->factory->buildToolExchangeWidget($node->primary, $node->secondary, $this->theme)
            : $this->factory->buildWidget($node->primary, $this->theme);

        $this->setContent($content);
        $this->node = $node;
    }

    private function sameRenderedSource(?TranscriptBlock $previous, ?TranscriptBlock $next): bool
    {
        if ($previous === $next) {
            return true;
        }
        if (null === $previous || null === $next
            || $previous->id !== $next->id || $previous->kind !== $next->kind
            || $previous->runId !== $next->runId || $previous->seq !== $next->seq
            || $previous->meta !== $next->meta || $previous->streaming !== $next->streaming
            || $previous->collapsed !== $next->collapsed) {
            return false;
        }

        // The owning renderer decides whether text supplies a header/body or is
        // hidden by the current preview policy. Other inputs remain conservative.
        return $previous->text === $next->text || !$this->factory->toolTextCanAffectPresentation($next);
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
