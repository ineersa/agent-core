<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\Tui\Screen\ChatScreen;

/**
 * Syncs catalog/live-view child state and clears status-panel keys for subagent live.
 *
 * Needs-input lifecycle is owned by {@see SubagentLiveCatalog} latches so a
 * pending tool question is not erased by stale nonterminal parent
 * subagent_progress before the next snapshot. Answer/cancel/terminal paths
 * clear through the catalog; selected live-view activity is refreshed here.
 */
final class SubagentLiveAttention
{
    public static function markChildNeedsInputForRun(TuiSessionState $state, ChatScreen $screen, string $agentRunId): void
    {
        // Catalog latch is durable for this TUI session and works even when the
        // progress row has not arrived yet (tool_question before first progress).
        $state->subagentLiveCatalog->markNeedsInputForRun($agentRunId);

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId)
                ?? $state->subagentLiveCatalog->findByAgentRunId($agentRunId);
            if (null !== $refreshed) {
                $live->selected = $refreshed;
            }
            $live->childActivity = RunActivityStateEnum::WaitingHuman;
        }

        self::refreshAttentionFooter($state, $screen);
    }

    public static function clearWaitingHumanForRun(TuiSessionState $state, ChatScreen $screen, string $agentRunId): void
    {
        // Always drop the latch first so a row that was already overwritten to
        // running by a race cannot re-promote on the next nonterminal progress.
        $state->subagentLiveCatalog->clearNeedsInputForRun($agentRunId, restoreRunningIfWaiting: true);

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId)
                ?? $state->subagentLiveCatalog->findByAgentRunId($agentRunId);
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
        // Cancel clears the latch without restoring Running; terminal Cancelled wins.
        $state->subagentLiveCatalog->clearNeedsInputForRun($agentRunId, restoreRunningIfWaiting: false);

        $existing = $state->subagentLiveCatalog->findByAgentRunId($agentRunId);
        if (null !== $existing) {
            $state->subagentLiveCatalog->applyChildStatus($existing->artifactId, SubagentLiveStatusEnum::Cancelled);
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $agentRunId) {
            $refreshed = $state->subagentLiveCatalog->findByArtifactId($live->selected->artifactId)
                ?? $state->subagentLiveCatalog->findByAgentRunId($agentRunId);
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

            $state->subagentLiveCatalog->clearNeedsInputForRun($catalogChild->agentRunId, restoreRunningIfWaiting: false);
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
     * Clears persistent subagent_live / agents-live status-panel rows on every sync.
     */
    public static function refreshAttentionFooter(TuiSessionState $state, ChatScreen $screen): void
    {
        // Attention is shown on inline transcript cards, picker markers, and live view — not status panel rows.
        $screen->setStatus('subagent_live', null);
        $screen->setStatus('agents-live', null);
    }
}
