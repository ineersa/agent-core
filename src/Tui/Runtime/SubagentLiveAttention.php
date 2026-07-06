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
    public static function markChildNeedsInputForRun(TuiSessionState $state, ChatScreen $screen, string $agentRunId): void
    {
        foreach ($state->subagentLiveCatalog->all() as $catalogChild) {
            if ($catalogChild->agentRunId !== $agentRunId) {
                continue;
            }

            if (SubagentLiveStatusEnum::WaitingHuman !== $catalogChild->status) {
                $state->subagentLiveCatalog->applyChildStatus($catalogChild->artifactId, SubagentLiveStatusEnum::WaitingHuman);
            }
            break;
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId);
            if (null !== $refreshed) {
                $live->selected = $refreshed;
            }
            $live->childActivity = RunActivityStateEnum::WaitingHuman;
        }

        self::refreshAttentionFooter($state, $screen);
    }

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

        self::refreshAttentionFooter($state, $screen);
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

        self::refreshAttentionFooter($state, $screen);
    }

    /**
     * Parent cancel owns foreground child lifecycle: optimistically mark active/waiting
     * catalog children cancelled so picker/footer do not stay on needs input until
     * terminal subagent_progress arrives from the runtime.
     */
    public static function markActiveChildrenCancelledForParentCancel(TuiSessionState $state, ChatScreen $screen): void
    {
        $touched = false;
        foreach ($state->subagentLiveCatalog->all() as $catalogChild) {
            if (!$catalogChild->status->isActive()) {
                continue;
            }

            $state->subagentLiveCatalog->applyChildStatus($catalogChild->artifactId, SubagentLiveStatusEnum::Cancelled);
            $touched = true;
        }

        if (!$touched) {
            return;
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId);
            if (null !== $refreshed) {
                $live->selected = $refreshed;
            }
            if ($live->childActivity->isActive() || RunActivityStateEnum::Cancelling === $live->childActivity) {
                $live->childActivity = RunActivityStateEnum::Cancelling;
            } elseif (!$refreshed?->status->isTerminal()) {
                $live->childActivity = RunActivityStateEnum::Cancelled;
            }
        }

        self::refreshAttentionFooter($state, $screen);
    }

    public static function syncMainAttention(TuiSessionState $state, ChatScreen $screen): void
    {
        self::refreshAttentionFooter($state, $screen);
    }

    /**
     * Keeps the main-screen subagent_live attention marker aligned with the catalog.
     * Live-view context uses working message and transcript — not status-panel keys.
     */
    public static function refreshAttentionFooter(TuiSessionState $state, ChatScreen $screen): void
    {
        $child = $state->subagentLiveCatalog->firstChildNeedingAttention();
        if (null !== $child) {
            $screen->setStatus(
                'subagent_live',
                \sprintf('⚠ Subagent %s needs your input — /agents-live', $child->agentName),
            );
        } else {
            $screen->setStatus('subagent_live', null);
        }

        // Defensive: never show keyed status rows for live-view-only context.
        $screen->setStatus('agents-live', null);
    }
}
