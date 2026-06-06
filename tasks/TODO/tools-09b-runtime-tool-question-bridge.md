# TOOLS-09B Runtime bridge for bash background confirmation questions

## Goal
## Goal
Wire the bash tool's user-controlled background confirmation prompt to the existing TUI question infrastructure without importing TUI code into tool/runtime workers.

This follows TOOLS-09, which implements bash as a background-managed foreground-supervised tool with a prompt adapter that defaults to decline. TOOLS-09B replaces/augments that default adapter with a real runtime/TUI bridge.

## Scope
- Reuse existing TUI question infrastructure:
  - `src/Tui/Question/QuestionRequest.php`
  - `src/Tui/Question/QuestionCoordinator.php`
  - `src/Tui/Question/QuestionController.php`
  - `QuestionSource::Tui`, `QuestionKind::Confirm`, `transcript=false`.
- Add a runtime-safe tool question request/answer bridge for local tool prompts such as `Command still running after 30s. Move to background?`.
- The tool/runtime side must not depend on `src/Tui/` classes.
- Expose a runtime event/protocol payload for a pending tool-local question, including at minimum:
  - `request_id`
  - `run_id` / session id where available
  - `tool_call_id` where available
  - `tool_name` = `bash`
  - `pid`
  - `log_path`
  - safe command preview, not raw sensitive output
  - prompt text
  - kind = confirm
  - transcript = false
- TUI `RuntimeEventPoller` or an adjacent deptrac-safe coordinator detects the runtime event and enqueues a local `QuestionRequest(source: QuestionSource::Tui, kind: QuestionKind::Confirm, transcript: false, allowOther: false)`.
- User answer is sent back through `AgentSessionClient` / runtime protocol, not by directly injecting TUI services into tools.
- Add a runtime command for answering local tool questions, e.g. `answer_tool_prompt`, or an equivalent typed protocol command.
- Bash supervisor receives the decision:
  - accept: return `Moved to background. PID: N, Log: <path>` and leave process running under `BackgroundProcessManager`.
  - decline: continue foreground supervision until completion, timeout, cancellation, or a future prompt policy decision.
  - cancel/reject: treat as decline unless product decision says otherwise; document chosen semantics.
- Local tool questions must not create transcript blocks or canonical HITL `answer_human` traffic.
- Keep live log streaming/display out of scope; the runtime event may carry `log_path` so a later task can implement TUI log tailing.

## Out of scope
- Do not implement live bash output streaming in the TUI.
- Do not use `ask_human` / `answer_human` for local bash background prompts.
- Do not persist local bash prompts as transcript blocks.
- Do not make the model choose backgrounding via a tool parameter.

## Implementation notes
- TOOLS-09's `BashBackgroundPromptAdapterInterface` (or equivalent) should become the tool-side abstraction. This task provides a production implementation that waits for/resolves a runtime-mediated decision.
- Prefer a small DTO/protocol object under `src/CodingAgent/Runtime/Contract` or `Protocol`, plus controller/process transport support, over ad-hoc arrays.
- Respect architecture boundaries: TUI talks through `AgentSessionClient` and runtime protocol only; `src/Tui/` must not import AgentCore internals or `BackgroundProcessManager`.
- If the command/log path is shown to the user, cap/truncate command preview and avoid raw prompt/tool output in logs per `docs/datadog.md` privacy rules.

## Dependencies
- Depends on TOOLS-09.
- Reuses QH-01/QH-02 question DTO/controller infrastructure already present.
- Related to but distinct from QH-07: QH-07 binds AgentCore HITL (`ask_human`/`answer_human`) to questions; this task binds local tool prompts and must keep `transcript=false`.

## Acceptance criteria
- A long-running bash command triggers a TUI confirmation question at the configured threshold.
- The TUI question uses existing `QuestionCoordinator` / `QuestionController` and is `QuestionSource::Tui`, `QuestionKind::Confirm`, `transcript=false`.
- Accepting leaves the already-started `BackgroundProcessManager` process running and returns PID/log path to the model.
- Declining keeps bash supervised in foreground until completion, timeout, or cancellation.
- No duplicate command is launched when accepting backgrounding.
- No local bash background prompt is persisted as a transcript block or sent as `answer_human`.
- TUI/runtime boundary remains deptrac-clean; TUI does not depend on `BackgroundProcessManager`, and tool code does not depend on `src/Tui/`.
- Focused tests cover accept, decline, cancellation/rejection semantics, and protocol serialization/transport where applicable.
- Required validation is run through Castor, including `castor check` before handoff unless environment prerequisites are unavailable.

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
- Created: 2026-05-31T18:02:46.913Z

## Task workflow update - 2026-06-06T23:33:02.611Z
- Summary: Design clarification after review: maximize reuse of the existing HITL/TUI question infrastructure. Do not build a parallel question system for bash background prompts. Reuse QuestionCoordinator, QuestionRequest, QuestionController overlay, RuntimeEventPoller/TickPollListener callback wiring, and AgentSessionClient/UserCommand transport patterns wherever possible. The distinction from SafeGuard is lifecycle, not UI: SafeGuard is pre-tool approval that enters AgentCore WaitingHuman and resumes via answer_human; bash background confirmation is a mid-running local tool prompt inside BashTool::shouldBackground() that needs a boolean answer and must remain transcript=false. Implementation should add only minimal adapter/bridge glue needed to surface a local tool prompt to the existing question overlay and return the answer to the blocked bash tool. Do not route local bash background prompts through ask_human/answer_human or canonical WaitingHuman unless the task is explicitly re-scoped. Any dedicated protocol/event/command should be minimal and justified by the mid-tool/cross-process answer-delivery requirement, not by creating a new question architecture. Prefer extracting/shared helpers from the existing human_input.requested handling if that avoids duplication. Avoid introducing a DB-backed pending-question resolver unless exploration proves existing runtime mechanisms cannot safely deliver the answer back to the tool worker process.
- User clarified that existing SafeGuard/HITL question infrastructure is already implemented and working; TOOLS-09B must maximize reuse rather than inventing a separate question/resolver system.
- Implementation guidance updated: reuse existing TUI question coordinator/overlay/DTOs and runtime listener shape; add only the smallest local-tool bridge necessary for BashTool background confirmation.
