# RENDER-04: Tool cards with fenced YAML args and normal output previews

## Goal
Part of .pi/plans/tui-rich-transcript-blocks-plan.md.

Order: depends on RENDER-01 and RENDER-02. Can be developed in parallel with RENDER-03. RENDER-05 builds on this for diff-classified tool results.

Scope:
- Render tool calls and tool results as compact/subtle cards with semantic headers.
- Tool call arguments must be visible and rendered as fenced YAML in the tool-call card.
- Implement normal `ToolResult` line previews using `tui.transcript.previews.tool_result_lines`.
- Respect `TranscriptDisplayState::previewableBlocksExpanded` for normal tool results.
- Do not preview/collapse tool calls themselves.
- Keep errors generous/full by default; do not hide useful failures behind tiny previews.

Parallelism: after RENDER-02, this can run in parallel with RENDER-03. RENDER-06 can implement listener plumbing in parallel but needs this for full behavior validation.

## Acceptance criteria
- Tool call blocks render as cards with tool name/status header.
- Tool call arguments are visible as fenced YAML.
- Tool result blocks render as cards with readable output.
- Long normal tool results are previewed to `tool_result_lines` when preview mode is collapsed.
- Normal tool results expand when `previewableBlocksExpanded=true`.
- Tool calls, user messages, assistant messages, thinking, system, progress, question, approval, cancelled, and error blocks are not affected by normal result preview truncation.
- Focused Castor validation is reported for tool card rendering and preview behavior.

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
- Created: 2026-05-22T19:08:57.316Z
