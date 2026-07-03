<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\Tui\Screen\ChatScreen;

/**
 * Clears optimistic subagent_live / picker attention when the TUI knows a child
 * left waiting_human before the next parent subagent_progress snapshot.
 */
final class SubagentLiveAttention
{
    public static function clearWaitingHumanForRun(TuiSessionState $state, ChatScreen $screen, string $agentRunId): void
    {
        foreach ($state->subagentLiveCatalog->all() as $catalogChild) {
            if ($catalogChild->agentRunId !== $agentRunId) {
                continue;
            }
            if (SubagentLiveStatusEnum::WaitingHuman !== $catalogChild->status) {
                continue;
            }

            $state->subagentLiveCatalog->applyChildStatus($catalogChild->artifactId, SubagentLiveStatusEnum::Running);
            break;
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId);
            if (null !== $refreshed) {
                $live->selected = $refreshed;
            }
            if (RunActivityStateEnum::WaitingHuman === $live->childActivity) {
                $live->childActivity = RunActivityStateEnum::Running;
            }
        }

        if (!$state->subagentLiveView->active) {
            self::syncMainAttention($state, $screen);
        }
    }

    public static function markCancelledForRun(TuiSessionState $state, ChatScreen $screen, string $agentRunId): void
    {
        foreach ($state->subagentLiveCatalog->all() as $catalogChild) {
            if ($catalogChild->agentRunId !== $agentRunId) {
                continue;
            }

            $state->subagentLiveCatalog->applyChildStatus($catalogChild->artifactId, SubagentLiveStatusEnum::Cancelled);
            break;
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId);
            if (null !== $refreshed) {
                $live->selected = $refreshed;
            }
            if ($live->childActivity->isActive() || RunActivityStateEnum::Cancelling === $live->childActivity) {
                $live->childActivity = RunActivityStateEnum::Cancelling;
            } else {
                $live->childActivity = RunActivityStateEnum::Cancelled;
            }
        }

        if (!$state->subagentLiveView->active) {
            self::syncMainAttention($state, $screen);
        }
    }

    public static function syncMainAttention(TuiSessionState $state, ChatScreen $screen): void
    {
        $child = $state->subagentLiveCatalog->firstChildNeedingAttention();
        if (null !== $child) {
            $screen->setStatus(
                'subagent_live',
                \sprintf('⚠ Subagent %s needs your input — /agents-live', $child->agentName),
            );

            return;
        }

        $screen->setStatus('subagent_live', null);
    }
}
