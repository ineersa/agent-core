# Implement image paste support in TUI editor

## Goal
Created from GitHub issue #119. Investigation found Symfony TUI handles bracketed text paste only; it does not provide image clipboard paste semantics. Terminal image paste requires explicit protocol/clipboard handling (Kitty/iTerm2/WezTerm or fallback) plus a product decision on storage and message representation.

Issue: https://github.com/ineersa/agent-core/issues/119

Scout findings:
- Current EditorWidget/Symfony TUI paste path is text-only (`BracketedPasteTrait`, `EditorDocument::handlePaste()` sanitizes UTF-8 and strips control bytes).
- Ctrl+V is not bound for image paste.
- User message runtime path is text-only today (`StartRunRequest` prompt string), while image support exists agent-side through `view_image(path)` and `image_ref` conversion.
- Needs design before implementation: terminal protocols to support, storage location, editor UX, fallback behavior, replay/resume implications.

Recommended MVP direction to refine:
- Store pasted images under `.hatfield/sessions/<id>/attachments/` for replay/resume rather than `/tmp`.
- Insert a visible reference into the editor, e.g. markdown-ish `![pasted image](path)` or plain path, initially relying on `view_image` support.
- Provide clear unsupported-terminal feedback.
- Consider direct user-message attachments as a later larger design after text/reference MVP.

## Acceptance criteria
- Document supported terminal/image paste mechanisms and unsupported fallback behavior.
- Pasted images are validated and saved safely in a session-scoped attachments location or another explicitly approved location.
- Editor shows a clear inserted reference/placeholder for pasted images.
- Submitted prompts can lead the agent/model to inspect the pasted image without requiring the user to manually manage hidden state.
- Session resume/replay behavior for pasted image references is defined and tested.
- Real TmuxHarness/manual terminal validation exists where automatable; protocol-specific behavior is documented if not fully tmux-testable.
- GitHub issue #119 is referenced from the task metadata/history.

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
- Created: 2026-06-12T18:41:51.955Z
