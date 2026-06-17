# Capture raw real LLM stream chunks for DurableResultConverter debugging

## Goal
Context: normal `.hatfield/sessions/*/events.jsonl` persists only canonical coarse events (`llm_step_completed`, final `assistant_message.tool_calls`, `tool_execution_start/end`, etc.). Transient streaming deltas are `seq=0` runtime/TUI events and raw provider SSE chunks are not saved. Existing `StreamRecorderObserver` / `castor llm:fixtures:record` captures converted Symfony AI deltas, not raw provider chunks. To debug/upstream DurableResultConverter behavior around multiple/parallel tool calls and phantom/empty-id chunks, we need an opt-in way to capture the complete raw provider stream for a single turn.

Goal: add a safe, opt-in capture path around `src/Platform/Bridge/Generic/DurableResultConverter.php::convertStream()` that records every raw decoded provider `$data` chunk, plus enough metadata to correlate with converted deltas/canonical events, for real live LLM turns exhibiting multiple tool calls.

Important constraints:
- Must be disabled by default and never run in normal QA/castor check.
- Must not leak private data unexpectedly; require explicit env/config opt-in and write to a clearly named local debug path.
- Must support capturing a complete turn with multiple tool calls from real providers using the Generic/OpenAI-compatible path (z.ai/llama_cpp/llama_cpp_test/deepseek where applicable).
- Keep DurableResultConverter behavior unchanged when capture is disabled.
- Prefer a structured JSONL artifact that can be attached to issues/upstream reports and turned into deterministic tests.

## Acceptance criteria
- Add opt-in raw stream capture for DurableResultConverter, guarded by explicit env var and/or debug config, disabled by default.
- Captured artifact records each raw `$data` chunk in order as JSONL, with timestamp/ordinal and correlation metadata where available (provider/model/run_id/step_id if feasible, or documented limitations if not available at converter layer).
- Capture artifact also includes or can be correlated with converted deltas (`tool_call_start`, `tool_input_delta`, `tool_call_complete`, text/thinking) so raw→converted behavior is inspectable for multiple tool calls.
- Provide a command or documented workflow to run one real live turn with multiple tool calls and capture the stream completely.
- Add tests proving capture is disabled by default, writes valid JSONL when enabled, and does not alter DurableResultConverter output.
- Document where files are written, safety/privacy implications, and how to use the artifact for DurableResultConverter regression tests/upstream reports.
- Do not persist raw chunks into normal session `events.jsonl` unless explicitly opted in; normal sessions remain canonical/coarse by default.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/llm-raw-stream-capture
Worktree: /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture
Fork run: k7hal44ctfdn
PR URL: https://github.com/ineersa/agent-core/pull/157
PR Status: open
Started: 2026-06-17T16:19:29.050Z
Completed:

## Work log
- Created: 2026-06-17T14:08:36.110Z

## Task workflow update - 2026-06-17T16:19:29.051Z
- Moved TODO → IN-PROGRESS.
- Created branch task/llm-raw-stream-capture.
- Created worktree /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture.

## Task workflow update - 2026-06-17T16:19:58.452Z
- Recorded fork run: 7mapik7lmh2r
- Started implementation fork 7mapik7lmh2r in /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture with minimal env-only raw stream capture scope and run:agent-capture Castor command instructions.

## Task workflow update - 2026-06-17T16:30:52.794Z
- Recorded fork run: 7mapik7lmh2r
- Validation: fork: `castor test --filter=DurableResultConverterTest` — 15 tests, 87 assertions, OK; fork: `castor test` — 2573 tests, 7587 assertions, OK; fork: `castor deptrac` — 0 violations; fork: `castor phpstan` — 0 errors; fork: `castor cs-check` — clean; parent verification: `git status --short && git log --oneline -1 && git diff --stat HEAD~1..HEAD` in worktree — clean checkout; latest commit 72686ed10; expected 6-file diff stat
- Summary: Implementation fork completed and committed 72686ed10 (`feat: add opt-in raw stream capture for DurableResultConverter`). Verified worktree commit and expected diff stat: 6 files changed (`.castor/run.php`, `docs/llm-replay.md`, `docs/settings.md`, `SymfonyAiProviderFactory.php`, `DurableResultConverter.php`, `DurableResultConverterTest.php`) with 531 insertions / 44 deletions. Fork confirmed it read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before test work. Scope implemented: minimal env-only raw stream capture, Generic provider wiring, `castor run:agent-capture`, docs, and unit coverage.

## Task workflow update - 2026-06-17T16:44:22.029Z
- Summary: Pre-PR reviewer subagent returned REQUEST CHANGES. Blockers: actual env-gated JSONL writer untested; duplicate capture_end on stream error; duplicate capture_start from factory and converter; file handle not flushed/closed; incomplete stream emits clean capture_end before throwing; usage TokenUsage not captured/should be documented; security concern about permissive file/dir permissions. Will send implementation fork to address blockers before creating PR.

## Task workflow update - 2026-06-17T16:54:03.807Z
- Recorded fork run: k7hal44ctfdn
- Validation: fork: `castor test --filter=DurableResultConverterTest|SymfonyAiProviderFactoryTest` — 22 tests, 119 assertions, OK; fork: `castor test` — 2576 tests, 7611 assertions, OK; fork: `castor deptrac` — 0 violations, 0 errors; fork: `castor phpstan` — 0 errors, 0 file errors; fork: `castor cs-check` — clean; parent verification: `git status --short --branch && git log --oneline -5 && git diff --stat origin/main...HEAD` — clean worktree; latest commit aa01c867a; expected 7-file diff stat
- Summary: Fix fork completed and committed aa01c867a (`fix: address pre-PR reviewer blockers for raw stream capture`). Addressed reviewer blockers: factory/writer JSONL tests, duplicate capture markers, incomplete/error artifact semantics, usage delta correlation, provider_id enrichment, flushing, and restrictive permissions. Parent verified worktree clean and latest diff stat: 7 files changed, 715 insertions, 47 deletions against origin/main.

## Task workflow update - 2026-06-17T17:00:40.489Z
- Summary: Post-fix reviewer subagent returned APPROVED with non-blocking suggestions only. Prior blockers resolved and acceptance criteria met. Suggestions noted: optional regression tests for error/incomplete single capture_end, document run_id/step_id/model limitation, and minor comments/cleanup; no merge-blocking issues.

## Task workflow update - 2026-06-17T17:01:19.083Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 1200s)...
- castor check passed (28.0s).
- Pushed task/llm-raw-stream-capture to origin.
- branch 'task/llm-raw-stream-capture' set up to track 'origin/task/llm-raw-stream-capture'.
- Created PR: https://github.com/ineersa/agent-core/pull/157
