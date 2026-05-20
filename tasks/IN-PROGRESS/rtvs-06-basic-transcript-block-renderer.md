# RTVS-06 Basic TranscriptBlock renderer in TUI

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add or adapt TUI transcript rendering to render TranscriptBlock DTOs plainly.
- Render at least user message, assistant message, assistant thinking, tool preview/result, question/approval placeholder, cancelled, and error blocks.
- Keep output simple: no rich markdown, no interactive question form, no final tool widgets.
- Use existing TUI theme tokens and role prefixes where sensible.

Exclusions:
- Do not implement TranscriptProjector logic.
- Do not refactor RuntimeEventPoller integration; RTVS-07 owns that.
- Do not implement local TUI question widgets; see .pi/plans/tui-question-hitl-plan.md.

Dependencies: RTVS-02.
Parallelizable with: RTVS-03, RTVS-04, RTVS-05.

## Acceptance criteria
- TUI can render a static list of TranscriptBlock DTOs without relying on rendered strings in transcript.jsonl.
- Renderer covers required block kinds with readable plain output.
- Renderer does not import AgentCore internals.
- Focused tests or snapshot-style unit tests cover representative blocks.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/rtvs-06-basic-transcript-block-renderer
Worktree: /home/ineersa/projects/agent-core-worktrees/rtvs-06-basic-transcript-block-renderer
Fork run:
PR URL:
PR Status:
Started: 2026-05-20T02:59:44.377Z
Completed:

## Work log
- Created: 2026-05-17T22:16:59.539Z

## Task workflow update - 2026-05-20T02:55:17.137Z
- Scout results for RTVS-06 recorded from two parallel scouts. Pi-mono scout found core patterns: component tree with Container/TUI differential render loop, assistant/user/tool/bash components, streaming lifecycle message_start/update/end, tool calls rendered as separate components, collapsed/truncated previews for long output, theme-driven colors, and no virtual scrolling. Key paths: /home/ineersa/claw/pi-mono/packages/tui/src/tui.ts; packages/tui/src/components/{markdown,text,box,truncated-text}.ts; packages/coding-agent/src/modes/interactive/components/{assistant-message,tool-execution,user-message,bash-execution,visual-truncate}.ts; interactive-mode.ts handleEvent/renderSessionContext/addMessageToChat. Symfony TUI scout found agent-core should keep its own TuiWidget/TuiRenderContext and reuse vendor primitives selectively: Symfony\Component\Tui\Ansi\TextWrapper for ANSI-safe wrapping, AnsiUtils for visible width/truncation/ANSI stripping, Style/Color/BorderPattern for styling/panels if needed. Do not extend Symfony AbstractWidget or use Symfony RenderContext for RTVS-06; implement project-native TranscriptBlock renderer/widget. Recommended prefix/theme mapping: UserMessage ❯ UserMessage, AssistantMessage ◇ AssistantMessage, AssistantThinking ⋯ ThinkingText, ToolCall/ToolResult ● Tool/ToolOutput, Progress ⏳ Muted, Question ? Accent, Approval 🔐 Warning, Cancelled/Error ✕ Muted/Error, System · SystemMessage. Full subagent output: /home/ineersa/.pi/agent/tmp/2026-05--5e87f5ee.txt

## Task workflow update - 2026-05-20T02:59:44.377Z
- Moved TODO → IN-PROGRESS.
- Created branch task/rtvs-06-basic-transcript-block-renderer.
- Created worktree /home/ineersa/projects/agent-core-worktrees/rtvs-06-basic-transcript-block-renderer.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/rtvs-06-basic-transcript-block-renderer.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/rtvs-06-basic-transcript-block-renderer.
- Summary: Starting RTVS-06: implement basic project-native TranscriptBlock rendering in TUI using existing theme tokens and Symfony TUI ANSI-safe wrapping primitives.
