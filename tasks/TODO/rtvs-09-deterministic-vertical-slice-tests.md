# RTVS-09 Deterministic runtime transcript vertical slice tests

## Goal
Plan: .pi/plans/runtime-transcript-vertical-slice-plan.md

Scope:
- Add deterministic tests for the full projection path: user prompt -> runtime event sequence -> persisted runtime events -> transcript blocks -> rendered basic transcript.
- Prefer AI-11 trace replay/provider fixtures if available.
- Assert session files are created/updated correctly under .hatfield/sessions/<id>/.
- Include cancellation/failure coverage with a visible non-generic block.

Exclusions:
- Do not require live network/model calls.
- Do not run tmux e2e tests as part of this task unless explicitly requested.
- Do not implement missing production APIs solely for tests.

Dependencies: RTVS-07, RTVS-08; AI-11 preferred.
Parallelizable with: none after dependencies.

## Acceptance criteria
- Focused tests cover a complete deterministic vertical slice without live model access.
- Tests assert visible user and assistant transcript blocks plus persisted runtime-events.jsonl/transcript projection data.
- Tests include cancellation or failure block behavior.
- Tests use production constructors/factories or test-local fixtures only; no test-only production APIs.
- Relevant castor test filters pass and castor deptrac passes.

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
- Created: 2026-05-17T22:17:19.969Z
