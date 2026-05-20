<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Transcript widget that renders {@see TranscriptBlock} DTOs using
 * the project-native renderer.
 *
 * This is the TUI-facing entry point for RTVS-06. When blocks are present
 * they are rendered with role prefixes, semantic theme colors, and
 * ANSI-safe word wrapping. When no blocks are set, a welcome message
 * is shown (mirroring the existing {@see TranscriptWidget} behavior).
 *
 * For RTVS-07, the runtime poller/projector pipeline feeds blocks into
 * this widget via {@see setBlocks()} without layout changes.
 */
final class TranscriptBlockWidget implements TuiWidget
{
    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    public function __construct(
        private readonly TranscriptBlockRenderer $renderer = new TranscriptBlockRenderer(),
    ) {
    }

    /** @return list<TranscriptBlock> */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /** @param list<TranscriptBlock> $blocks */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = $blocks;
    }

    public function addBlock(TranscriptBlock $block): void
    {
        $this->blocks[] = $block;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if ([] === $this->blocks) {
            return [$context->theme->muted('  Welcome to Agent Core. Type a message below to start.')];
        }

        $lines = [];
        foreach ($this->blocks as $block) {
            array_push($lines, ...$this->renderer->renderBlock($block, $context));
        }

        return $lines;
    }
}
