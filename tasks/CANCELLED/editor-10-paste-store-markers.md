# EDITOR-10 Paste store and paste marker handling

**CANCELLED 2026-05-18** — 95% covered by Symfony TUI: BracketedPasteTrait detects ESC[200~/ESC[201~, EditorDocument::handlePaste() creates markers for >10-line pastes, EditorDocument::getText() auto-expands markers on submit. Only session-attachment storage might be needed later, which can be a tiny follow-up if ever required.

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Detect bracketed paste if exposed by Symfony TUI, otherwise parse raw ESC[200~/ESC[201~ markers in EditorInputRouter.
- Insert small paste content directly at cursor, preserving newlines.
- For large paste (>10 lines or >1000 chars), store payload and insert compact marker.
- Add PasteStore and PasteMarker model; initially in memory, with session attachment path ready for later persistence.
- Expand paste markers into full payload when submitted to runtime while preserving compact UI text.

Exclusions:
- No image paste in this task.
- No configurable thresholds unless trivial.
- No shell command execution.

Dependencies: EDITOR-01, EDITOR-02.
Parallelizable with: EDITOR-09.

## Acceptance criteria
- Small paste inserts direct text at cursor.
- Large paste inserts marker and stores payload.
- Submitted text expands markers to full payload for runtime submission.
- Cursor/delete behavior around markers is deterministic and tested.
- No paste payload is lost when marker remains in editor state.
- castor deptrac passes.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-18T00:16:21.614Z
