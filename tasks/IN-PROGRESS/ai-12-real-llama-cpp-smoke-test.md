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
Status: IN-PROGRESS
Branch: task/ai-12-real-llama-cpp-smoke-test
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test
Fork run: h3g97wjipi3c
PR URL:
PR Status:
Started: 2026-05-17T22:21:19.224Z
Completed:

## Work log
- Created: 2026-05-16T22:02:34.212Z

## Task workflow update - 2026-05-17T22:21:19.224Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-12-real-llama-cpp-smoke-test.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test.

## Task workflow update - 2026-05-17T22:21:59.285Z
- Recorded fork run: h3g97wjipi3c
- Summary: Started AI-12 implementation in worktree /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test. Fork scope: add opt-in real llama.cpp smoke test that is skipped unless explicitly enabled/configured, reads LLAMA_CPP_BASE_URL/LLAMA_CPP_MODEL and optional API key, uses a tiny deterministic prompt, asserts non-empty response and selected model persistence, captures usage if available, documents how to run, and optionally adds a Castor task such as test:llm-real without affecting default castor check.
