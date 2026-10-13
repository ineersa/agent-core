<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;

/** Bounded indexed human-prompt pages. Full text is read only on selection. */
final readonly class SessionHistoryProvider implements HistoryProviderInterface
{
    public function __construct(private SessionRunEventStore $eventStore, private RunHistoryIndex $historyIndex)
    {
    }

    public function forSession(string $runId, ?int $before = null, ?int $after = null): HistoryView
    {
        $source = $this->eventStore->historySource($runId);
        $page = $this->historyIndex->promptPage($source->log, $source->path, $runId, $before, $after);
        $prompts = [];
        foreach ($page['rows'] as $row) {
            $prompts[] = new HistoryPromptView($row['turn_no'], $row['preview'], $row['anchor']);
        }

        return new HistoryView($prompts, $page['selected'], $page['older'], $page['newer']);
    }

    public function promptText(string $runId, int $turnNo): string
    {
        $source = $this->eventStore->historySource($runId);

        return $this->historyIndex->selectPrompt($source->log, $source->path, $runId, $turnNo)['text'];
    }
}
