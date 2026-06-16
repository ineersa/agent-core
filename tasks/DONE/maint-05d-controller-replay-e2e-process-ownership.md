# MAINT-05D Controller replay E2E and explicit process ownership

## Goal
## Context

Fourth stage of the cardinal QA/test rework. Port controller/runtime E2E away from routine live LLM calls and make subprocess ownership explicit so failed tests do not leave messenger/controller consumers behind.

Current problem:
- Controller E2E spawns a controller process that can spawn messenger consumers.
- Failed or killed tests can leave consumers/orphans around.
- Tests currently depend on live llama.cpp for routine behavior proof.

Dependencies:
- Prefer after MAINT-05C LLM replay foundation.
- Castor command wiring should follow MAINT-05A.

Known entrypoints:
- `tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php`
- `tests/CodingAgent/Runtime/Controller/E2E/ControllerSmokeTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/WriteFileToolE2eTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/ViewImageToolE2eTest.php`
- `tests/CodingAgent/Runtime/Controller/E2E/OutputCapReadFileControllerTest.php`
- runtime/controller/messenger process spawning code under `src/CodingAgent/Runtime/` and CLI command wiring.

## Acceptance criteria
- Controller/runtime E2E has a deterministic replay mode and default automated tests use replay rather than live llama.cpp.
- At least one controller replay E2E proves a realistic tool-call flow using replay fixtures from MAINT-05C.
- Controller process ownership is explicit: parent controller, messenger consumers, and any child process groups have a teardown contract that runs on success and failure.
- Failed controller E2E tests do not rely on broad stale-killer cleanup to remove consumers. Orphan prevention is designed into the harness/process manager.
- Controller E2E tests use targeted event proof helpers, not broad sleeps or full-run waits unless full-run completion is the behavior under test.
- Live controller/LLM smoke remains available as opt-in validation but is not part of default deterministic QA.
- Validation uses Castor only: controller replay E2E command, relevant unit/integration tests, `castor deptrac`, `castor phpstan`, `castor cs-check`, and opt-in live smoke if prerequisites are available.
- Task handoff records process tree behavior, cleanup proof, and before/after live LLM usage in controller tests.

## Workflow metadata
Status: DONE
Branch: task/maint-05d-controller-replay-e2e-process-ownership
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership
Fork run: lcn5ct5erqxi
PR URL: https://github.com/ineersa/agent-core/pull/145
PR Status: merged
Started: 2026-06-15T23:32:27.282Z
Completed: 2026-06-16T00:10:19.294Z

## Work log
- Created: 2026-06-15T21:07:41.944Z

## Task workflow update - 2026-06-15T21:13:34.621Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, especially deterministic controller replay E2E and process ownership/cleanup checks; live controller smoke remains explicit opt-in.

## Task workflow update - 2026-06-15T21:35:22.567Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after prior MAINT-05 stages are merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05D.
- Sequential execution policy: do not start MAINT-05D until prior MAINT-05 stages that it depends on are manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05D; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-15T23:32:27.282Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05d-controller-replay-e2e-process-ownership.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership.
- Summary: Started MAINT-05D after MAINT-05C was merged/done. Main remains the MAINT-05 integration branch and eventual PR targets main. Per MAINT-05A-F policy, skip automated reviewer and full `LLM_MODE=true castor check`; run focused deterministic controller replay/process-ownership validation and keep live controller/LLM smoke explicit opt-in only.

## Task workflow update - 2026-06-15T23:33:21.128Z
- Recorded fork run: es8l1fx2p88s
- Validation: Pending fork run `es8l1fx2p88s`. Expected focused validation: `castor list`, deterministic controller replay E2E Castor command/test (no live LLM), focused process ownership/cleanup test(s), `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; live smoke only if explicit/prereqs available.
- Summary: Launched implementation fork for MAINT-05D in `/home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership`. Fork instructed to port controller/runtime E2E default path to deterministic replay using MAINT-05C fixtures/replay seams, keep live controller smoke opt-in, design explicit process ownership/teardown for controller and messenger consumers, add cleanup proof/diagnostics, update Castor/docs, and run focused validation only. Fork has strict cwd/branch guard to avoid committing to main, must not push, must not use destructive git operations, and must not run full `LLM_MODE=true castor check`.

## Task workflow update - 2026-06-15T23:49:43.318Z
- Recorded fork run: es8l1fx2p88s
- Validation: Fork guard passed: cwd/root `/home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership`, branch `task/maint-05d-controller-replay-e2e-process-ownership`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`, and `docs/llm-replay.md`.; Fork validation: `php -l` on new PHP files passed.; Fork validation: `castor list` passed and shows `test:controller-replay` plus live opt-in `test:controller`.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 7.7s.; Fork validation: `castor test --filter=ControllerReplaySmokeTest` passed: 1 test, 14 assertions, 9.3s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 36.7s.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in.
- Summary: Implementation fork completed MAINT-05D. Added deterministic controller replay E2E and explicit process ownership/teardown. New replay HTTP seam is activated by `HATFIELD_LLM_REPLAY_FIXTURE_PATH`, converting MAINT-05C fixture deltas to OpenAI-compatible SSE so controller subprocesses exercise the real Symfony AI provider/parser path without live llama.cpp. Added `test:controller-replay` Castor command, replay controller test base, tool-call replay proof test/fixture, process ownership PID tracking/teardown diagnostics, and docs updates. Commit: `4f563397` (`MAINT-05D: Controller replay E2E and explicit process ownership`). Parent verified worktree is clean and diff against `origin/main` shows expected 10 files.

## Task workflow update - 2026-06-15T23:50:04.587Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05d-controller-replay-e2e-process-ownership to origin.
- branch 'task/maint-05d-controller-replay-e2e-process-ownership' set up to track 'origin/task/maint-05d-controller-replay-e2e-process-ownership'.
- Created PR: https://github.com/ineersa/agent-core/pull/145
- Validation: Pre-move inspection: task worktree clean on `task/maint-05d-controller-replay-e2e-process-ownership` at `4f563397`.; Parent diff inspection: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership diff --stat origin/main...HEAD` showed expected 10 files for controller replay/process ownership foundation.; Fork validation: `php -l` on new PHP files passed.; Fork validation: `castor list` passed and shows `test:controller-replay` plus live opt-in `test:controller`.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 7.7s.; Fork validation: `castor test --filter=ControllerReplaySmokeTest` passed: 1 test, 14 assertions, 9.3s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 36.7s.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy / MAINT-05G owns final full-gate validation.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in.
- Summary: Moved MAINT-05D to CODE-REVIEW. Branch contains implementation commit `4f563397` with deterministic controller replay E2E, replay HTTP seam, explicit process ownership/teardown, tool-call replay proof, `test:controller-replay` Castor command, and docs updates. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction.

## Task workflow update - 2026-06-15T23:53:23.151Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Validation: Read PR #145 review comments via `gh pr view` and `gh api repos/ineersa/agent-core/pulls/145/comments`.; Actionable comments: (1) no test code in real layer; set proper client in `services_test` container and use existing injected `$httpClient` path in `SymfonyAiProviderFactory`; (2) `ReplayHttpClientFactory` must live under tests if testing-related code.
- Summary: Moved MAINT-05D back to IN-PROGRESS to address PR #145 review feedback. Comments require removing test/replay code from production `src/` layer and wiring replay HTTP client through the test container/services_test injection path instead of env-var branching inside `SymfonyAiProviderFactory`.

## Task workflow update - 2026-06-15T23:54:47.574Z
- Recorded fork run: lcn5ct5erqxi
- Validation: Read PR #145 review comments: production `SymfonyAiProviderFactory` must not contain replay/test branching; `ReplayHttpClientFactory` must live under tests if it is testing-related.; Fork `lcn5ct5erqxi` pending. Expected focused validation: `castor list`, `castor test:controller-replay`, `castor test --filter=ControllerReplaySmokeTest`, relevant small replay/service wiring check if applicable, `castor test` if feasible, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; no reviewer; no push.
- Summary: Launched review-iteration fork to address PR #145 comments. Required fix: remove replay/test code from production `src/` layer, remove the replay env-var branch from `SymfonyAiProviderFactory`, move replay HTTP client factory under `tests/`, wire it through `config/services_test.yaml` so the existing injected `$httpClient` path is used, and make controller replay subprocess use `APP_ENV=test`/test DI rather than production factory branching. Fork must preserve deterministic `castor test:controller-replay`, explicit process ownership/teardown, and opt-in live controller smoke.

## Task workflow update - 2026-06-16T00:07:13.742Z
- Recorded fork run: lcn5ct5erqxi
- Validation: Fork guard passed: cwd/root `/home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership`, branch `task/maint-05d-controller-replay-e2e-process-ownership`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`, and `docs/llm-replay.md`.; Fork validation: `php -l` on changed PHP files passed.; Fork validation: `castor list` passed and shows `test:controller-replay`, `test:controller`, `llm:fixtures:record`, and `llm:fixtures:info`.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 7.5s.; Fork validation: `castor test --filter=ControllerReplaySmokeTest` passed: 1 test, 14 assertions, 7.5s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 33.9s.; Fork validation: `castor deptrac` passed with 0 violations/errors.; Fork validation: `castor phpstan` passed with 0 errors/file_errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in.; Parent verification: worktree clean at `d893276b`; grep found replay activation only in `config/services_test.yaml` comments and tests, not in production `src/CodingAgent`.
- Summary: Review-iteration fork completed PR #145 feedback. Removed all replay/test code from production `src/`: deleted/moved `ReplayHttpClientFactory` to test namespace `Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplayHttpClientFactory`, restored `SymfonyAiProviderFactory::getHttpClient()` to only use injected `$httpClient` or normal timeout fallback, wired replay HTTP client through `config/services_test.yaml`, and changed controller replay subprocess to `APP_ENV=test` with source `bin/console` so test DI/services are loaded. Also updated test messenger config to honor per-run Doctrine transport env vars with `in-memory://` defaults, preserving normal unit/kernel tests. Commit: `d893276b` (`MAINT-05D: Address PR #145 review feedback — remove test code from production src/`). Parent verified worktree is clean and branch is 1 commit ahead of origin PR branch.

## Task workflow update - 2026-06-16T00:07:47.036Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05d-controller-replay-e2e-process-ownership to origin.
- branch 'task/maint-05d-controller-replay-e2e-process-ownership' set up to track 'origin/task/maint-05d-controller-replay-e2e-process-ownership'.
- PR already exists: https://github.com/ineersa/agent-core/pull/145
- Validation: Pre-move inspection: task worktree clean on `task/maint-05d-controller-replay-e2e-process-ownership` at `d893276b`, branch 1 commit ahead of origin PR branch.; Parent diff inspection: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership diff --stat origin/main...HEAD` showed expected 12 files for original controller replay implementation plus review fix.; Parent grep verification: replay activation references exist only in `config/services_test.yaml` comments and tests, not in production `src/CodingAgent`.; Fork validation: `php -l` on changed PHP files passed.; Fork validation: `castor list` passed and shows `test:controller-replay`, `test:controller`, `llm:fixtures:record`, and `llm:fixtures:info`.; Fork validation: `castor test:controller-replay` passed: 1 test, 14 assertions, 7.5s.; Fork validation: `castor test --filter=ControllerReplaySmokeTest` passed: 1 test, 14 assertions, 7.5s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 33.9s.; Fork validation: `castor deptrac` passed with 0 violations/errors.; Fork validation: `castor phpstan` passed with 0 errors/file_errors.; Fork validation: `castor cs-check` passed with 0 fixable files.; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy / MAINT-05G owns final full-gate validation.; Live LLM smoke not run; llama.cpp unavailable and live smoke remains opt-in.
- Summary: Moved MAINT-05D back to CODE-REVIEW after addressing PR #145 feedback. Branch now includes review-fix commit `d893276b` removing replay/test code from production `src/`, moving the replay HTTP factory to tests, restoring `SymfonyAiProviderFactory` to DI-only client injection/default timeout behavior, wiring replay through `services_test.yaml`, and preserving deterministic controller replay/process ownership validation. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction.

## Task workflow update - 2026-06-16T00:10:19.294Z
- Moved CODE-REVIEW → DONE.
- Merged task/maint-05d-controller-replay-e2e-process-ownership into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/maint-05d-controller-replay-e2e-process-ownership.
- Pulled integration checkout: Already up to date..
- Validation: Verified PR #145 state via `gh pr view`: MERGED at 2026-06-16T00:09:25Z.; Synced integration checkout with `git pull --ff-only`; main fast-forwarded to merge commit `ba48be09` for PR #145.; Focused validation recorded before review: `php -l` on changed PHP files, `castor list`, `castor test:controller-replay` (1 test, 14 assertions, 7.5s), `castor test --filter=ControllerReplaySmokeTest` (1 test, 14 assertions, 7.5s), `castor test` (2520 tests, 7359 assertions, 33.9s), `castor deptrac`, `castor phpstan`, and `castor cs-check` passed.; No post-merge full `LLM_MODE=true castor check` run per MAINT-05A-F policy/user instruction; MAINT-05G owns final full-gate validation.
- Summary: PR #145 was merged by the user. Moved MAINT-05D to DONE after syncing `main` from GitHub. Per MAINT-05A-F stage policy, skipped post-merge full `LLM_MODE=true castor check`; MAINT-05G remains responsible for final full-gate validation/metrics.
