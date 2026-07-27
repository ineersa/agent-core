<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Stable outer transcript child that keeps its ContainerWidget identity/order while
 * content inside may be replaced.
 *
 * {@see TranscriptMountedWidget} mounts one of these per visual node. Ordinary streaming and
 * tool-result pairing update or replace only the inner content so the outer transcript
 * container does not need clear()+ordered add() for those transitions.
 */
final class TranscriptVisualNodeWidget extends ContainerWidget
{
    private ?AbstractWidget $content = null;

    public function content(): ?AbstractWidget
    {
        return $this->content;
    }

    /**
     * Replace the single content child. Detaches the previous content and attaches the new one.
     * The wrapper itself stays mounted under the transcript container.
     */
    public function setContent(AbstractWidget $content): void
    {
        if ($this->content === $content) {
            return;
        }

        $this->clear();
        $this->content = $content;
        $this->add($content);
    }
}
