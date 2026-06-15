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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

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
