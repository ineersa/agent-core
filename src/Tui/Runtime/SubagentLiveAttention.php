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
        self::reconcileMainTranscriptFromCatalog($state, $screen);
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
     * Overlay catalog running/waiting_human onto projected subagent_progress rows.
     *
     * Parent projection may keep status=running while SafeGuard holds the child;
     * the catalog latch is authoritative for nonterminal running/waiting_human only.
     * Parallel mode updates children[] rows, not the wrapper. Mutates transcript +
     * screen only when a status actually changes.
     */
    private static function reconcileMainTranscriptFromCatalog(TuiSessionState $state, ChatScreen $screen): void
    {
        $catalog = $state->subagentLiveCatalog;
        $blocks = $state->transcript;
        $changed = false;

        foreach ($blocks as $i => $block) {
            $progress = $block->meta['subagent_progress'] ?? null;
            if (!\is_array($progress)) {
                continue;
            }

            $progressChanged = false;
            $children = $progress['children'] ?? null;
            // Parallel: reconcile each child row only (wrapper has no agent_run_id).
            // Single: reconcile the top-level progress row itself.
            $rows = \is_array($children) ? $children : [$progress];
            $nextRows = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    $nextRows[] = $row;
                    continue;
                }

                $runId = $row['agent_run_id'] ?? null;
                if (!\is_string($runId) || '' === trim($runId)) {
                    $nextRows[] = $row;
                    continue;
                }

                $child = $catalog->findByAgentRunId(trim($runId));
                // Catalog only overlays Running/WaitingHuman; projected terminal wins.
                if (null === $child || !\in_array($child->status, [SubagentLiveStatusEnum::Running, SubagentLiveStatusEnum::WaitingHuman], true)) {
                    $nextRows[] = $row;
                    continue;
                }

                $statusRaw = $row['status'] ?? null;
                $current = \is_string($statusRaw) ? $statusRaw : 'running';
                $normalized = match ($current) {
                    'needs_clarification' => 'waiting_human',
                    'starting' => 'running',
                    default => $current,
                };
                // Terminal/unknown projected statuses are left alone.
                if (!\in_array($normalized, ['running', 'waiting_human'], true)) {
                    $nextRows[] = $row;
                    continue;
                }

                $desired = $child->status->value;
                if ($normalized === $desired) {
                    $nextRows[] = $row;
                    continue;
                }

                $row['status'] = $desired;
                $nextRows[] = $row;
                $progressChanged = true;
            }

            if (!$progressChanged) {
                continue;
            }

            if (\is_array($children)) {
                $progress['children'] = $nextRows;
            } else {
                $progress = $nextRows[0] ?? $progress;
            }

            $meta = $block->meta;
            $meta['subagent_progress'] = $progress;
            $blocks[$i] = $block->with(meta: $meta);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $state->transcript = $blocks;
        $screen->setTranscriptBlocks($blocks);
    }
}
