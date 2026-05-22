# RENDER-03: Markdown rendering for user, assistant, and thinking blocks

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: depends on RENDER-01 and RENDER-02.

Scope:
- Render `UserMessage`, `AssistantMessage`, and visible `AssistantThinking` through Symfony `MarkdownWidget`.
- Apply subdued dim/italic treatment for visible thinking using Symfony styling where possible.
- Render hidden thinking as one `⋯ Thinking` placeholder per thinking block when `tui.transcript.thinking.visible=false`.
- Ensure thinking is config-only in v1 and is not previewable.
- Ensure user/assistant/thinking blocks are unaffected by preview expansion state.

Parallelism: after RENDER-02, this can run in parallel with RENDER-04. It should not depend on RENDER-05 diff rendering.

## Acceptance criteria
- User messages render through `MarkdownWidget`.
- Assistant messages render through `MarkdownWidget`.
- Visible thinking renders through `MarkdownWidget` with dim/italic or configured fallback style.
- Hidden thinking renders a `⋯ Thinking` placeholder once per hidden thinking block.
- Thinking visibility is read from `TranscriptDisplayConfig`, not from mutable session state.
- Preview expansion state and `Ctrl+O` do not affect user, assistant, or thinking rendering.
- Focused Castor validation is reported for transcript rendering behavior.

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
- Created: 2026-05-22T19:08:49.664Z
