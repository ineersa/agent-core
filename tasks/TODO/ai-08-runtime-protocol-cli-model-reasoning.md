# AI-08 Add runtime protocol and CLI inputs for model/reasoning

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-08--add-runtime-protocol-and-cli-inputs-for-modelreasoning

Goal: allow initial model/reasoning to enter the system from CLI/TUI/process clients.

Depends on: AI-01.

Parallelism: can run alongside AI-02 and AI-03 after AI-01; unblocks AI-10 and AI-14.

Scope:
- Extend `StartRunRequest` with optional `model` and `reasoning` fields.
- Extend JSONL protocol payloads for process runtime.
- Update `AgentCommand` CLI options: `--model`, `--reasoning`.
- Update `InteractiveMode`, `SessionInitializer`, `SubmitListener`, `InProcessAgentSessionClient`, and `JsonlProcessAgentSessionClient` as needed to preserve and forward fields.
- Keep backward compatibility when fields are absent.

## Acceptance criteria
- Headless and TUI starts can pass model/reasoning.
- Existing start-run call sites compile and work with null fields.
- JSONL clients ignore/omit absent fields safely.
- Suggested validation: `castor test --filter Runtime`; `castor deptrac`.

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
- Created: 2026-05-16T22:02:34.212Z
