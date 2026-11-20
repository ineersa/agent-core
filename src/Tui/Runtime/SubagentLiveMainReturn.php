<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Screen\ChatScreen;

/** Leaves child live view and restores the main session. */
final class SubagentLiveMainReturn
{
    public static function returnToMain(
        TuiSessionState $state,
        ChatScreen $screen,
        QuestionCoordinator $questionCoordinator,
        QuestionController $questionController,
        SubagentLiveChildViewPoller $childPoller,
        ?AgentSessionClient $client = null,
        bool $requestRender = true,
    ): void {
        if (!$state->subagentLiveView->active) {
            return;
        }

        $selected = $state->subagentLiveView->selected;
        if (null !== $selected) {
            $questionCoordinator->removeForRun($selected->agentRunId);
            $questionController->close();
            $client?->endObservingChildRun($selected->agentRunId);
        }

        $state->subagentLiveView->exit();
        $screen->setStatus('agents-live', null);
        $screen->setTranscriptBlocks($state->transcript);
        $screen->syncQueuedUserMessages($state->queuedUserMessages);
        $screen->setWorkingMessage(null);
        // Restore main-agent editor border from session footerReasoning (never overwritten by child).
        $screen->applyEditorBorderColor($state->footerReasoning);
        if ($requestRender) {
            $screen->requestRender(true);
        }
        $childPoller->resetProjection();
    }
}
