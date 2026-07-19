<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Screen\ChatScreen;

/**
 * Shared return-to-main behavior for /agents-main and Ctrl+\ toggle.
 *
 * Callers that own a QuestionController must close the visual overlay after this
 * returns (coordinator request stays pending for the owning child view).
 */
final class SubagentLiveMainReturn
{
    public static function returnToMain(TuiSessionState $state, ChatScreen $screen, ?AgentSessionClient $client = null, bool $requestRender = true): void
    {
        if (!$state->subagentLiveView->active) {
            return;
        }

        $selected = $state->subagentLiveView->selected;
        if (null !== $client && null !== $selected) {
            $client->endObservingChildRun($selected->agentRunId);
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
