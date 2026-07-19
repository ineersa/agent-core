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
            // Selected children always carry a stable artifactId catalog key.
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
        // Always drop the latch first so a row that was already overwritten to
        // running by a race cannot re-promote on the next nonterminal progress.
        $state->subagentLiveCatalog->clearNeedsInputForRun($agentRunId, restoreRunningIfWaiting: true);

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
        // Row may be absent (pre-progress cancel): still drop the run-id latch.
        // When a row exists, applyChildStatus(Cancelled) also clears the latch.
        $state->subagentLiveCatalog->clearNeedsInputForRun($agentRunId, restoreRunningIfWaiting: false);

        $existing = $state->subagentLiveCatalog->findByAgentRunId($agentRunId);
        if (null !== $existing) {
            $state->subagentLiveCatalog->applyChildStatus($existing->artifactId, SubagentLiveStatusEnum::Cancelled);
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

            // applyChildStatus(Cancelled) clears the latch for this existing row.
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
        // Overlay catalog attention onto projected parent subagent cards so main
        // still shows ⚠ needs input while progress meta stays running.
        self::reconcileMainTranscriptStatuses($state, $screen);
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

    /**
     * Reconcile projected subagent_progress statuses from the live catalog latch.
     *
     * Parent projection may keep status=running while SafeGuard holds the child;
     * the catalog latch is authoritative for nonterminal running/waiting_human.
     * Only mutates transcript + screen when a status actually changes.
     */
    private static function reconcileMainTranscriptStatuses(TuiSessionState $state, ChatScreen $screen): void
    {
        $changed = false;
        $blocks = $state->transcript;
        foreach ($blocks as $i => $block) {
            $progress = $block->meta['subagent_progress'] ?? null;
            if (!\is_array($progress)) {
                continue;
            }

            $reconciled = self::reconcileProgressStatus($progress, $state->subagentLiveCatalog);
            if ($reconciled === $progress) {
                continue;
            }

            $meta = $block->meta;
            $meta['subagent_progress'] = $reconciled;
            $blocks[$i] = $block->with(meta: $meta);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $state->transcript = $blocks;
        $screen->setTranscriptBlocks($blocks);
    }

    /**
     * @param array<string, mixed> $progress
     *
     * @return array<string, mixed>
     */
    private static function reconcileProgressStatus(array $progress, SubagentLiveCatalog $catalog): array
    {
        $children = $progress['children'] ?? null;
        if (\is_array($children)) {
            $nextChildren = [];
            $childrenChanged = false;
            foreach ($children as $child) {
                if (!\is_array($child)) {
                    $nextChildren[] = $child;
                    continue;
                }
                $reconciledChild = self::reconcileSingleProgressStatus($child, $catalog);
                if ($reconciledChild !== $child) {
                    $childrenChanged = true;
                }
                $nextChildren[] = $reconciledChild;
            }
            if ($childrenChanged) {
                $progress['children'] = $nextChildren;
            }
        }

        return self::reconcileSingleProgressStatus($progress, $catalog);
    }

    /**
     * @param array<string, mixed> $progress
     *
     * @return array<string, mixed>
     */
    private static function reconcileSingleProgressStatus(array $progress, SubagentLiveCatalog $catalog): array
    {
        $runId = $progress['agent_run_id'] ?? null;
        if (!\is_string($runId) || '' === trim($runId)) {
            return $progress;
        }

        $child = $catalog->findByAgentRunId(trim($runId));
        if (null === $child) {
            return $progress;
        }

        // Only overlay catalog running/waiting_human onto nonterminal projected rows.
        // Terminal projection (completed/failed/cancelled) must win over the latch.
        if (!\in_array($child->status, [SubagentLiveStatusEnum::Running, SubagentLiveStatusEnum::WaitingHuman], true)) {
            return $progress;
        }

        $statusRaw = $progress['status'] ?? null;
        $current = \is_string($statusRaw) ? $statusRaw : 'running';
        $normalized = match ($current) {
            'needs_clarification' => 'waiting_human',
            'starting' => 'running',
            default => $current,
        };
        if (!\in_array($normalized, ['running', 'waiting_human'], true)) {
            return $progress;
        }

        $desired = $child->status->value;
        if ($normalized === $desired) {
            return $progress;
        }

        $progress['status'] = $desired;

        return $progress;
    }
}
