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
Status: CODE-REVIEW
Branch: task/ai-12-real-llama-cpp-smoke-test
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-12-real-llama-cpp-smoke-test
Fork run: h3g97wjipi3c
PR URL: https://github.com/ineersa/agent-core/pull/20
PR Status: open
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

## Task workflow update - 2026-05-17T23:39:55.954Z
- Validation: php -l LlamaCppSmokeTest.php and .castor/tasks.php: passed; php bin/console --no-interaction: passed; vendor/bin/phpunit --list-groups: shows llm-real (1 test); vendor/bin/phpunit --group llm-real: passed with 1 skipped by default; castor test: passed (353 tests, 8122 assertions, 1 notice); castor deptrac: passed (0 violations); castor phpstan: passed (0 errors); castor cs-fix + castor cs-check: clean; castor check: quality ok; castor list: shows test:llm-real; main checkout untouched
- Summary: AI-12 implemented by fork h3g97wjipi3c in commit 53e41281 on branch task/ai-12-real-llama-cpp-smoke-test. Added opt-in real llama.cpp smoke test with PHPUnit #[Group('llm-real')], skipped unless LLAMA_CPP_SMOKE_TEST=1 and LLAMA_CPP_BASE_URL are set. Test builds real Generic provider + Platform, real ModelResolverRoutingSubscriber/SessionAwareModelResolver, real LlmPlatformAdapter with InMemoryRunStore, sends deterministic prompt, asserts non-empty assistant response, preserves session metadata model, and tolerates missing usage. Added Castor task test:llm-real and excluded llm-real from default castor test/check path.

## Task workflow update - 2026-05-17T23:40:40.424Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-12-real-llama-cpp-smoke-test to origin.
- branch 'task/ai-12-real-llama-cpp-smoke-test' set up to track 'origin/task/ai-12-real-llama-cpp-smoke-test'.
- Created PR: https://github.com/ineersa/agent-core/pull/20
- Validation: php bin/console --no-interaction: passed; vendor/bin/phpunit --group llm-real: passed with 1 skipped by default; castor test: passed (353 tests, 8122 assertions, 1 notice); castor deptrac: passed (0 violations); castor phpstan: passed (0 errors); castor cs-fix + castor cs-check: clean; castor check: quality ok; castor list: shows test:llm-real
- Summary: AI-12 ready for review. Implemented opt-in real llama.cpp smoke test in commit 53e41281: #[Group('llm-real')] test skipped unless LLAMA_CPP_SMOKE_TEST=1 and LLAMA_CPP_BASE_URL are set; invokes real Generic provider path through Platform, ModelResolverRoutingSubscriber/SessionAwareModelResolver, and LlmPlatformAdapter; asserts non-empty response and session metadata model preservation; usage optional. Added castor test:llm-real and excluded llm-real from default castor test/check.
