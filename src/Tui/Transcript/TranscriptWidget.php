<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Default transcript/history widget.
 *
 * Displays conversation entries. For v1, shows a welcome message
 * when the transcript is empty.
 *
 * @todo Wire actual transcript entries from the runtime event stream.
 */
final class TranscriptWidget implements TuiWidget
{
    /** @var list<TranscriptEntry> */
    private array $entries = [];

    /** @return list<TranscriptEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /** @param list<TranscriptEntry> $entries */
    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    public function addEntry(TranscriptEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if ([] === $this->entries) {
            return [$context->theme->muted('  Welcome to Agent Core. Type a message below to start.')];
        }

        $lines = [];
        foreach ($this->entries as $entry) {
            $lines[] = $entry->render($context);
        }

        return $lines;
    }
}
