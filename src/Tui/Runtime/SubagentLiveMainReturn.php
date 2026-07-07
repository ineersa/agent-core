<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\Tui\Screen\ChatScreen;

/**
 * Shared return-to-main behavior for /agents-main and Ctrl+\ toggle.
 */
final class SubagentLiveMainReturn
{
    public static function returnToMain(TuiSessionState $state, ChatScreen $screen, bool $requestRender = true): void
    {
        if (!$state->subagentLiveView->active) {
            return;
        }

        $state->subagentLiveView->exit();
        $screen->setStatus('agents-live', null);
        $screen->setTranscriptBlocks($state->transcript);
        $screen->syncQueuedUserMessages($state->queuedUserMessages);
        $screen->setWorkingMessage(null);
        if ($requestRender) {
            $screen->requestRender(true);
        }
    }
}
