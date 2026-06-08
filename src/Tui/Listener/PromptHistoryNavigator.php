<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * Cursor-only navigation over an external list of {@see TranscriptBlock}s.
 *
 * The navigator does NOT store or duplicate prompt strings.  It holds only a
 * block-level cursor and scans the caller-supplied block array for blocks of
 * kind {@see TranscriptBlockKindEnum::UserMessage}.  The caller (typically a
 * listener) supplies the current transcript blocks from
 * {@see \Ineersa\Tui\Runtime\TuiSessionState::$transcript} on every
 * navigation call — so memory does not grow in a separate list and no
 * separate persistence file is required.
 *
 * Navigation behaviour (shell-like):
 *  - Up   (previous):  walk backward to the previous user-message block.
 *  - Down (next):      walk forward to the next user-message block.  When
 *                       the cursor is at or past the newest block, the
 *                       editor is cleared and navigation is exited.
 *  - Typing normal input exits history navigation (cursor reset via
 *    {@see exitNavigation()}).
 *
 * The navigator is deliberately unaware of keys, terminals, or editor
 * widgets — it is a pure state machine over a {@see TranscriptBlock} array.
 *
 * Placed in the TuiListener layer (not TuiEditor) because deptrac allows
 * TuiListener → AppRuntimeProjection (TranscriptBlockKindEnum).
 */
final class PromptHistoryNavigator
{
    /**
     * Index into the TranscriptBlock array of the currently-viewed block,
     * or null when not navigating history.
     */
    private ?int $currentBlockIndex = null;

    /**
     * Try to move to the previous user-message block.
     *
     * Scans backward through $blocks from the cursor (or end when not
     * yet navigating) and returns the text of the first UserMessage block
     * found, or null when no previous user message exists.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function previous(array $blocks): ?string
    {
        if ([] === $blocks) {
            return null;
        }

        // Start searching from one position before the current cursor.
        // When not yet navigating (null cursor), start from the end so
        // the first Up press recalls the most recent prompt.
        $startIndex = $this->currentBlockIndex ?? \count($blocks);

        for ($i = $startIndex - 1; $i >= 0; --$i) {
            if (TranscriptBlockKindEnum::UserMessage === $blocks[$i]->kind) {
                $this->currentBlockIndex = $i;

                return $blocks[$i]->text;
            }
        }

        // No earlier user message found — stay at the first one if we
        // were already showing it, or remain not-navigating.
        return null;
    }

    /**
     * Try to move to the next user-message block.
     *
     * Scans forward through $blocks from the cursor.  If a newer
     * UserMessage block is found, returns its text.  If the cursor is
     * past the newest block, the editor should be cleared and navigation
     * exited (null return signals this to the caller).
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function next(array $blocks): ?string
    {
        if (null === $this->currentBlockIndex) {
            // Not navigating — nothing to go forward from.
            return null;
        }

        $startIndex = $this->currentBlockIndex + 1;

        for ($i = $startIndex; $i < \count($blocks); ++$i) {
            if (TranscriptBlockKindEnum::UserMessage === $blocks[$i]->kind) {
                $this->currentBlockIndex = $i;

                return $blocks[$i]->text;
            }
        }

        // Past the newest user message.  Clear the editor (caller
        // interprets null as "clear and exit navigation").
        $this->currentBlockIndex = null;

        return null;
    }

    /**
     * Whether the navigator is currently active (inside a history
     * navigation session).
     */
    public function isNavigating(): bool
    {
        return null !== $this->currentBlockIndex;
    }

    /**
     * Exit history navigation mode.
     *
     * After this call, the next {@see previous()} press will start from
     * the most recent block again (as if the user had never pressed Up).
     */
    public function exitNavigation(): void
    {
        $this->currentBlockIndex = null;
    }

    /**
     * Return the current block index for test assertions only.
     *
     * @internal
     */
    public function currentBlockIndex(): ?int
    {
        return $this->currentBlockIndex;
    }
}
