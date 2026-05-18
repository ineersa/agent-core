# QH-05 AgentCore interrupt compatibility for ask_human

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Ensure ToolExecutor treats ask_human as interrupt-compatible, alongside any existing ask_user fallback.
- Ensure ToolCallExtractor::interruptPayloadFromToolResult() preserves header, ui_kind/kind, choices, default, allow_other, and secret where available.
- Add tests that committing an ask_human tool result causes the existing WaitingHuman path.

Exclusions:
- Do not add a new blocking tool execution path.
- Do not implement TUI question widgets or answer routing.
- Do not replace existing WaitingHuman/HumanResponse flow.

Dependencies: QH-04.
Parallelizable with: QH-02, QH-03.

## Acceptance criteria
- ask_human tool result is detected as an interrupt.
- AgentCore transitions to WaitingHuman through existing handlers.
- Interrupt payload preserves UI metadata needed by runtime/TUI projection.
- No new blocking/oneshot tool execution path is introduced.
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
- Created: 2026-05-18T00:04:34.543Z
