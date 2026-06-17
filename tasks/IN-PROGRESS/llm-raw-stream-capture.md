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
Status: IN-PROGRESS
Branch: task/llm-raw-stream-capture
Worktree: /home/ineersa/projects/agent-core-worktrees/llm-raw-stream-capture
Fork run: 7mapik7lmh2r
PR URL:
PR Status:
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
