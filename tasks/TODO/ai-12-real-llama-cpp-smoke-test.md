# AI-12 Add opt-in real llama.cpp smoke test

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-12--add-opt-in-real-llamacpp-smoke-test

Goal: prove the configured generic provider can call the real local llama.cpp endpoint when available.

Depends on: AI-05, AI-06, AI-10.

Parallelism: can run alongside AI-11 and AI-13 once provider + routing path exists.

Scope:
- Add an opt-in external/integration test group or Castor task.
- Read provider details from Hatfield settings or env overrides: `LLAMA_CPP_BASE_URL`, `LLAMA_CPP_MODEL`.
- Use a tiny deterministic prompt.
- Assert non-empty assistant response and selected model persistence.
- Capture usage if provider returns it, but do not fail if usage is absent.

## Acceptance criteria
- Test is skipped unless explicitly configured.
- Default `castor check` is not blocked by missing llama.cpp.
- Document how to run it.
- Suggested validation: exact Castor task/name TBD, e.g. `castor test:llm-real`.

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
