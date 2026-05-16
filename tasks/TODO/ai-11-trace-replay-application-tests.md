# AI-11 Add trace replay application tests

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-11--add-trace-replay-application-tests

Goal: validate the full application path using recorded/replayed provider output.

Depends on: AI-05, AI-06, AI-10.

Parallelism: can run alongside AI-12 and AI-13 once provider + routing path exists.

Scope:
- Build replay provider/platform fixture that emits recorded streamed deltas or normalized results.
- Add curated trace fixture(s) stripped of secrets/local paths.
- Exercise AgentCore + runtime flow at application level.
- Assert model/reasoning resolution, message/tool-call persistence, usage/cost/context projections, and resume using session metadata instead of current global default.

## Acceptance criteria
- At least one replay test covers a successful assistant response.
- At least one replay test covers model/reasoning persistence across resume.
- Tests run without network access.
- Suggested validation: `castor test --filter TraceReplay`.

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
