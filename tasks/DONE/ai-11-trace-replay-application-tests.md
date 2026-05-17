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
Status: DONE
Branch: task/ai-11-trace-replay-application-tests
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests
Fork run: ufad6uzbrw8k
PR URL: https://github.com/ineersa/agent-core/pull/19
PR Status: merged
Started: 2026-05-17T22:14:04.277Z
Completed: 2026-05-17T22:20:49.121Z

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

## Task workflow update - 2026-05-17T22:19:14.728Z
- Validation: php bin/console --no-interaction: passed; vendor/bin/phpunit --filter TraceReplay: passed (4 tests, 26 assertions); castor test: passed (353 tests, 8122 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 329 allowed); vendor/bin/phpstan analyse: passed (0 errors); castor cs-fix + castor cs-check: clean; main checkout untouched except pre-existing user WIP plan files
- Summary: AI-11 implemented by fork ufad6uzbrw8k in commit 4994ce8e on branch task/ai-11-trace-replay-application-tests. Added fixture-driven offline trace replay tests only (no production changes): tests/AgentCore/Fixtures/traces/successful-response.json plus TraceReplayTest with test-only replay model client/result converter/token usage helpers. Coverage includes successful assistant replay through real Symfony Platform + LlmPlatformAdapter + ModelResolverRoutingSubscriber, model/reasoning resolution from session metadata, resume preference for session metadata over changed defaults, persistence of model/reasoning changes across resume, and thinking delta handling. AI-13 footer/status projections intentionally not implemented; current tests assert usage captured in PlatformInvocationResult where available.

## Task workflow update - 2026-05-17T22:19:26.799Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-11-trace-replay-application-tests to origin.
- branch 'task/ai-11-trace-replay-application-tests' set up to track 'origin/task/ai-11-trace-replay-application-tests'.
- Created PR: https://github.com/ineersa/agent-core/pull/19
- Validation: php bin/console --no-interaction: passed; vendor/bin/phpunit --filter TraceReplay: passed (4 tests, 26 assertions); castor test: passed (353 tests, 8122 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations, 329 allowed); vendor/bin/phpstan analyse: passed (0 errors); castor cs-fix + castor cs-check: clean
- Summary: AI-11 ready for review. Implemented offline fixture-driven trace replay application tests in commit 4994ce8e: real Symfony Platform + test model client/result converter, real ModelResolverRoutingSubscriber/SessionAwareModelResolver path, real LlmPlatformAdapter replay of streamed deltas, session metadata model/reasoning resume checks, and usage capture assertions. No production source changes.

## Task workflow update - 2026-05-17T22:20:49.121Z
- Moved CODE-REVIEW → DONE.
- Merged task/ai-11-trace-replay-application-tests into integration checkout.
- Merge made by the 'ort' strategy.
 .../Fixtures/traces/successful-response.json       |  22 +
 .../Infrastructure/SymfonyAi/TraceReplayTest.php   | 723 +++++++++++++++++++++
 2 files changed, 745 insertions(+)
 create mode 100644 tests/AgentCore/Fixtures/traces/successful-response.json
 create mode 100644 tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/ai-11-trace-replay-application-tests.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #19 merged; Final validation from implementation fork: php bin/console boot passed; TraceReplay tests passed (4 tests, 26 assertions); castor test passed (353 tests, 8122 assertions, 1 pre-existing notice); deptrac passed; phpstan passed; CS clean
- Summary: PR #19 merged. AI-11 complete: offline trace replay application tests added with curated fixture and real Symfony Platform + ModelResolverRoutingSubscriber + LlmPlatformAdapter path; covers successful assistant replay, usage capture, model/reasoning session metadata resolution, resume behavior, persistence, and thinking deltas. No production source changes.
