<?php

declare(strict_types=1);

namespace Ineersa\Tui\Status;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Status panel widget that renders keyed status entries.
 *
 * Designed to be driven by setStatus() data from TuiExtensionContext.
 * Each entry renders as a line with a left-aligned label and text value.
 *
 * This is typically rendered by ChatLayout::renderStatusPanel() using
 * data from the slot registry rather than as a standalone widget.
 * This class provides the same rendering logic for standalone use.
 */
final class StatusPanelWidget implements TuiWidget
{
    /** @var array<string, string> */
    private array $entries = [];

    /**
     * @param array<string, string> $entries
     */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function setEntry(string $key, ?string $text): void
    {
        if (null === $text) {
            unset($this->entries[$key]);
        } else {
            $this->entries[$key] = $text;
        }
    }

    /**
     * Replace all status entries.
     *
     * @param array<string, string> $entries
     */
    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    /**
     * @return array<string, string>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if ([] === $this->entries) {
            return [];
        }

        $lines = [];
        foreach ($this->entries as $key => $text) {
            $lines[] = $context->theme->muted(\sprintf('  %-12s %s', $key, $text));
        }

        return $lines;
    }
}
