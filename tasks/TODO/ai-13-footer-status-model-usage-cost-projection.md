# AI-13 Footer/status projection for model, reasoning, usage, and cost

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-13--footerstatus-projection-for-model-reasoning-usage-and-cost

Goal: expose runtime data needed by the footer without implementing the full picker yet.

Depends on: AI-05, AI-06, AI-10.

Parallelism: can run alongside AI-11 and AI-12 once provider + routing path exists; unblocks AI-14.

Scope:
- Add runtime/projection events or state for current model, reasoning level, token usage, cost estimate, context window usage, tokens/sec, session elapsed time, cwd and git branch if not already exposed.
- Use `FooterSegmentProvider`/`FooterDataProvider` extension points, not direct widget mutation.
- Format toward: `◆ model | thinking | tokens cost context% | ⚡ t/s | ⏱ time | ⌂ cwd | ⎇ branch`.

## Acceptance criteria
- Footer can show selected model and reasoning after run start.
- Usage/cost/context update after assistant result when metadata is available.
- TUI boundary stays clean: `src/Tui/` does not import AgentCore internals.
- Suggested validation: `castor test --filter Footer`; `castor deptrac`.

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
- Created: 2026-05-16T22:02:34.213Z
