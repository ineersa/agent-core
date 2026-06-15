# MAINT-05C LLM replay and fixture re-recording foundation

## Goal
## Context

Third stage of the cardinal QA/test rework. Normal QA must stop depending on live llama.cpp/OpenAI-compatible endpoints. Build a first-class deterministic LLM replay system and an explicit command to re-record fixtures when needed.

Current problem:
- Routine E2E and TUI tests hit live llama.cpp, which is unstable under parallel load and slow.
- There is existing trace replay test code, but no general record/replay system for runtime/controller/TUI tests.

Goal:
- Add a reusable replay fixture format and test-only replay path through the production LLM integration seams as much as practical.
- Add an explicit Castor command to re-record fixtures from live llama.cpp/provider.
- Keep live LLM smoke opt-in; default QA should use replay.

Known entrypoints:
- `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php`
- `src/AgentCore/Contract/Hook/BeforeProviderRequestHookInterface.php`
- `src/AgentCore/Contract/Hook/LlmStreamObserverInterface.php`
- `src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php`
- `src/CodingAgent/Infrastructure/SymfonyAi/ConfiguredSymfonyAiPlatformFactory.php`
- `config/services_test.yaml`
- `tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php`
- `tests/AgentCore/Fixtures/traces/successful-response.json`

## Acceptance criteria
- A documented LLM replay fixture format exists and captures request identity/metadata, prompt or chain identity, all streamed deltas needed by runtime/TUI, usage, stop reason, tool-call deltas, and relevant model metadata.
- A test-only replay implementation exists using existing Symfony AI/AgentCore seams where practical; avoid a disconnected fake that bypasses all meaningful runtime behavior.
- A recording path exists that can capture live provider output into replay fixtures. It may be opt-in via env/config and should not run during normal tests.
- A Castor command exists to re-record fixtures from live llama.cpp/provider explicitly; it is not part of default `castor check`.
- At least one realistic fixture is checked in and replayed by an automated test, including either a tool-call stream or a multi-turn stream complex enough to prove the design.
- Default unit/integration tests can use replay without requiring llama.cpp on port 9052.
- Live LLM smoke remains available as an opt-in command with clear docs, but default deterministic QA does not require it.
- Validation uses Castor only: replay tests, `castor deptrac`, `castor phpstan`, `castor cs-check`, and opt-in live re-record/smoke only if prerequisites are available.
- Docs/skills are updated to explain replay vs live modes and fixture re-record workflow.

## Workflow metadata
Status: DONE
Branch: task/maint-05c-llm-replay-recording-foundation
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation
Fork run: oye8a3xqq7qd
PR URL: https://github.com/ineersa/agent-core/pull/144
PR Status: merged
Started: 2026-06-15T22:50:40.122Z
Completed: 2026-06-15T23:31:48.341Z

## Work log
- Created: 2026-06-15T21:07:27.923Z

## Task workflow update - 2026-06-15T21:13:28.067Z
- Summary: MAINT-05 stage policy: this task belongs to umbrella branch `task/maint-05-cardinal-qa-test-rework`. When started and later moved to CODE-REVIEW, open the PR against that branch rather than `main`. Skip reviewer subagent and full `LLM_MODE=true castor check`; user will review manually and MAINT-05G owns final full-gate validation.
- PR base: use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` when moving this task to CODE-REVIEW.
- Review/validation exception for MAINT-05A-F: skip reviewer subagent; user reviews manually. Skip full `LLM_MODE=true castor check` until MAINT-05G. Run focused Castor validation for this stage only, especially replay-unit tests and fixture recording/replay commands; live LLM calls remain explicit opt-in only.

## Task workflow update - 2026-06-15T21:35:15.909Z
- Summary: Policy change: main is the MAINT-05 epic/integration branch. When this stage starts and later opens a PR, target `main` rather than `task/maint-05-cardinal-qa-test-rework`. Work proceeds sequentially after prior MAINT-05 stages are merged.
- Supersedes earlier PR-base notes in this task: do NOT use `prBaseBranch="task/maint-05-cardinal-qa-test-rework"`. Target `main` for MAINT-05C.
- Sequential execution policy: do not start MAINT-05C until prior MAINT-05 stages that it depends on are manually reviewed/merged to `main`, unless the user explicitly says otherwise.
- Validation/review policy remains: skip reviewer subagent and skip full `LLM_MODE=true castor check` for MAINT-05C; user reviews manually and MAINT-05G owns final full-gate validation.

## Task workflow update - 2026-06-15T22:50:40.122Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05c-llm-replay-recording-foundation.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation.
- Summary: Started MAINT-05C after MAINT-05B was merged/done. Main remains the MAINT-05 integration branch and eventual PR targets main. Per MAINT-05A-F policy, skip automated reviewer and full `LLM_MODE=true castor check`; run focused replay/recording validation and keep live LLM calls explicit opt-in only.

## Task workflow update - 2026-06-15T22:52:21.908Z
- Recorded fork run: oye8a3xqq7qd
- Validation: Pending fork run `oye8a3xqq7qd`. Expected focused validation: `castor list`, focused replay test via `castor test --filter=...`, `castor test` if feasible, `castor deptrac`, `castor phpstan`, `castor cs-check`; no full `LLM_MODE=true castor check`; live fixture recording only if explicitly available and safe.
- Summary: Launched implementation fork for MAINT-05C in `/home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation`. Fork instructed to build the deterministic LLM replay/fixture re-recording foundation, including documented fixture format, test-only replay implementation through existing Symfony AI/AgentCore seams, opt-in recording path, explicit Castor re-record command, replay tests with checked-in fixture, and testing docs updates. Fork has strict cwd/branch guard to avoid committing to main, must not push, must not use destructive git operations, and must not run full `LLM_MODE=true castor check`.

## Task workflow update - 2026-06-15T23:29:41.446Z
- Recorded fork run: oye8a3xqq7qd
- Validation: Fork guard passed: cwd/root `/home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation`, branch `task/maint-05c-llm-replay-recording-foundation`.; Fork read mandatory docs: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, `.agents/skills/task-workflow/SKILL.md`, `.agents/skills/castor/SKILL.md`.; Fork validation: `php -l` on all 8 new + 5 edited PHP files passed.; Fork validation: `castor list` passed and shows `llm:fixtures:record` / `llm:fixtures:info`.; Fork validation: `castor test --filter=ReplayTest` passed: 17 tests, 100 assertions, 0.8s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 26.9s, 0 skipped.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor cs-check` passed: 0 of 534 files can be fixed.; Skipped full `LLM_MODE=true castor check` per MAINT-05A-F policy/user instruction.
- Summary: Implementation fork completed MAINT-05C. Added deterministic LLM replay/recording foundation with documented fixture format (`docs/llm-replay.md`), reusable test-only replay helpers, stream recorder observer, tool-call fixture, replay tests, opt-in recording test scaffold, and Castor commands `llm:fixtures:record` / `llm:fixtures:info`. Extracted replay classes from `TraceReplayTest` and updated testing docs. Commit: `6a4e265a` (`MAINT-05C: LLM replay recording foundation`). Parent verified worktree is clean and diff against `origin/main` shows expected 13 files.

## Task workflow update - 2026-06-15T23:30:05.034Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/maint-05c-llm-replay-recording-foundation to origin.
- branch 'task/maint-05c-llm-replay-recording-foundation' set up to track 'origin/task/maint-05c-llm-replay-recording-foundation'.
- Created PR: https://github.com/ineersa/agent-core/pull/144
- Validation: Pre-move inspection: task worktree clean on `task/maint-05c-llm-replay-recording-foundation` at `6a4e265a`.; Parent diff inspection: `git -C /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation diff --stat origin/main...HEAD` showed expected 13 files for replay/recording foundation.; Fork validation: `php -l` on all 8 new + 5 edited PHP files passed.; Fork validation: `castor list` passed and shows `llm:fixtures:record` / `llm:fixtures:info`.; Fork validation: `castor test --filter=ReplayTest` passed: 17 tests, 100 assertions, 0.8s.; Fork validation: `castor test` passed: 2520 tests, 7359 assertions, 26.9s, 0 skipped.; Fork validation: `castor deptrac` passed with 0 violations.; Fork validation: `castor phpstan` passed with 0 errors.; Fork validation: `castor cs-check` passed: 0 of 534 files can be fixed.; Skipped reviewer subagent per user MAINT-05A-F policy.; Skipped full `LLM_MODE=true castor check` per user MAINT-05A-F policy / MAINT-05G owns final full-gate validation.
- Summary: Moved MAINT-05C to CODE-REVIEW. Branch contains implementation commit `6a4e265a` with replay/recording foundation, fixture docs, test-only replay helpers, opt-in Castor record/info commands, and focused replay validation. Automated reviewer and full `castor check` were skipped per MAINT-05 stage policy/user instruction.

## Task workflow update - 2026-06-15T23:31:48.341Z
- Moved CODE-REVIEW → DONE.
- Merged task/maint-05c-llm-replay-recording-foundation into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/maint-05c-llm-replay-recording-foundation.
- Pulled integration checkout: Already up to date..
- Validation: Synced integration checkout with `git pull --ff-only`; main fast-forwarded to merge commit `fa8db835` for PR #144.; Focused validation recorded before review: `php -l` on new/edited PHP files, `castor list`, `castor test --filter=ReplayTest` (17 tests, 100 assertions, 0.8s), `castor test` (2520 tests, 7359 assertions, 26.9s), `castor deptrac`, `castor phpstan`, and `castor cs-check` passed.; No post-merge full `LLM_MODE=true castor check` run per MAINT-05A-F policy/user instruction; MAINT-05G owns final full-gate validation.
- Summary: PR #144 was merged by the user. Moved MAINT-05C to DONE after syncing `main` from GitHub. Per MAINT-05 stage policy, skipped post-merge full `LLM_MODE=true castor check`; MAINT-05G remains responsible for final full-gate validation/metrics.
