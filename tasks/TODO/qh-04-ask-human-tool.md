# QH-04 ask_human tool and interrupt payload normalization

## Goal
Plan: .pi/plans/tui-question-hitl-plan.md

Scope:
- Add `src/CodingAgent/Tool/AskHumanTool.php` plus a Hatfield tool definition/provider for `ask_human` instead of relying on `#[AsTool]` metadata.
- Return kind=interrupt payload immediately; do not block waiting for input.
- Support prompt, header, kind, schema, choices, default, allow_other, secret, and optional question_id.
- Normalize bare string choices to label/description objects.
- Generate stable fallback question_id when absent.

Exclusions:
- Do not implement ToolExecutor compatibility beyond what is necessary for tool output; QH-05 owns pipeline compatibility.
- Do not implement TUI widgets or input routing.
- Do not implement a blocking/oneshot tool path like Codex.

Dependencies: TOOLS-R02 for Hatfield tool definition conventions, TOOLS-R03 for registry-backed Toolbox.
Parallelizable with: QH-01, QH-02, QH-03 after the TOOLS-R02/TOOLS-R03 conventions are stable.

## Acceptance criteria
- `ask_human` is discoverable through registry-backed Symfony Toolbox metadata and present in ToolRegistry permanent metadata.
- Tool result contains kind=interrupt, question_id, prompt, schema, normalized choices, and UI metadata.
- Unit tests cover text, confirm, choice, approval, and fallback id behavior.
- Tool returns immediately and does not wait for human input.
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
- Created: 2026-05-18T00:04:28.622Z
