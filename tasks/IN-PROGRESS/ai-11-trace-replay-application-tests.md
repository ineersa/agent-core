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
Status: IN-PROGRESS
Branch: task/ai-11-trace-replay-application-tests
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests
Fork run: ufad6uzbrw8k
PR URL:
PR Status:
Started: 2026-05-17T22:14:04.277Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.213Z

## Task workflow update - 2026-05-17T22:14:04.277Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-11-trace-replay-application-tests.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests.

## Task workflow update - 2026-05-17T22:14:47.256Z
- Recorded fork run: ufad6uzbrw8k
- Summary: Started AI-11 implementation in worktree /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests. Fork scope: add compact offline trace/replay application tests exercising real AgentCore/runtime/session flow with fake provider/platform boundary; cover successful assistant response and model/reasoning persistence across resume; avoid implementing AI-13 footer/status projection; preserve AI-10 Model namespace changes and architecture boundaries.
