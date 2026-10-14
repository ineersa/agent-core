<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryEntryView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;

/** Bounded indexed conversation pages. Full text is read only on selection. */
final readonly class SessionHistoryProvider implements HistoryProviderInterface
{
    public function __construct(private SessionRunEventStore $eventStore, private RunHistoryIndex $historyIndex)
    {
    }

    public function forSession(string $runId, ?int $before = null, ?int $after = null): HistoryView
    {
        $source = $this->eventStore->historySource($runId);
        $page = $this->historyIndex->entryPage($source->log, $source->path, $runId, $before, $after);
        $entries = [];
        foreach ($page['rows'] as $row) {
            $entries[] = new HistoryEntryView($row['turn_no'], $row['preview'], $row['anchor'], $row['role']);
        }

        return new HistoryView($entries, $page['selected'], $page['older'], $page['newer'], $page['selected_role']);
    }

    public function promptText(string $runId, int $turnNo): string
    {
        $source = $this->eventStore->historySource($runId);

        return $this->historyIndex->selectEntry($source->log, $source->path, $runId, $turnNo)['text'];
    }
}
