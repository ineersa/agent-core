# TOOLS-09 Implement bash tool with background-managed foreground supervision

## Goal
Implement the `bash` tool with registry-backed metadata, foreground-style execution semantics, output capping, timeout/cancellation handling, and safe user-controlled backgrounding semantics.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Important design update: do **not** start bash as an unmanaged foreground `Symfony\Component\Process\Process` and later try to adopt/detach it. Instead, every bash command starts through `BackgroundProcessManager::start($command)` immediately, so there is only one command execution. `BashTool` then supervises that background-managed process as if it were foreground until it completes, times out, is cancelled, or the user accepts backgrounding.

## Dependencies
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox, settings, and allowlist wiring).
- Depends on TOOLS-00 cancellation context / `ToolRuntime` / `ToolExecutionContextInterface` equivalents currently present in the codebase.
- Depends on TOOLS-02 (`OutputCap`).
- Depends on TOOLS-08 (`BackgroundProcessManager`).
- TOOLS-09B owns the production runtime/TUI question bridge for real interactive background confirmation.

## Scope
- Replace/complete `src/CodingAgent/Tool/BashTool.php`.
- Implement `BashTool` as a Hatfield built-in tool provider and handler:
  - `HatfieldToolProviderInterface`
  - `ToolHandlerInterface`
- Register `bash` through the registry-backed permanent tool convention, not `#[AsTool]` metadata.
- Tool definition schema must expose only:
  - `command: string`
  - `timeout?: integer`
- Do **not** add `run_in_background` or any model-controlled background parameter.
- Execute commands by calling `BackgroundProcessManager::start($command, $sessionId)` immediately.
- Supervise the started process in a foreground wait loop by polling `BackgroundProcessManager::list($sessionId)` / process record status and reading the command log.
- Use project/session context where available:
  - session/run id from ambient `ToolContext` for process ownership
  - project cwd according to existing app/runtime cwd conventions
- Capture command output from the background process log and pass final/partial text through `OutputCap`.
- On successful completion, return captured capped output.
- On non-zero exit, return captured capped output plus `Exit code N`.
- On timeout:
  - stop the managed process through `BackgroundProcessManager::stop($pid, $sessionId)`
  - return partial capped output plus a timeout notice.
- On run cancellation:
  - stop the managed process through `BackgroundProcessManager::stop($pid, $sessionId)` promptly
  - return structured/canonical cancellation details or a clear cancellation message with partial output.
- Add bash-specific settings if missing, for example under `tools.bash`:
  - `default_timeout_seconds` or reuse existing tool execution timeout where appropriate
  - `background_prompt_threshold_seconds` default `30`
  - any poll interval / log read cap if needed
  - reuse `tools.background_process.stop_grace_seconds` for termination grace unless a distinct bash setting is justified.
- Add a small injectable bash background prompt abstraction, for example `BashBackgroundPromptAdapterInterface`:
  - production default for TOOLS-09 is non-interactive and declines
  - focused tests may inject a fake adapter that accepts or declines
  - real TUI/runtime bridge is deferred to TOOLS-09B.
- At the configured prompt threshold:
  - ask the adapter: `Command still running after 30s. Move to background?`
  - if accepted, leave the already-started `BackgroundProcessManager` process running and return `Moved to background. PID: N, Log: <path>`
  - if declined, keep supervising until completion/timeout/cancellation.
- Ensure accepting backgrounding never launches a second copy of the command.
- Keep live TUI streaming/log display out of scope; the managed process already writes to `.hatfield/tmp/bg/` and TOOLS-09B/later tasks can expose it through runtime events.

## Out of scope
- No sandbox/allowlist.
- No model-controlled backgrounding.
- No live TUI output streaming.
- No production runtime/TUI question bridge; TOOLS-09B owns that.
- No direct dependency from tools/runtime code on `src/Tui/` question classes.
- No ANSI/binary output sanitization unless already trivial.

## Acceptance criteria
- `bash` tool is discoverable through registry-backed Symfony Toolbox metadata with only `command` and optional `timeout` parameters, and present in `ToolRegistryInterface` permanent metadata.
- `bash` starts exactly one process through `BackgroundProcessManager::start($command, $sessionId)` for each tool call.
- Foreground successful command returns captured capped output from the managed process log.
- Non-zero command returns output plus exit code information.
- Timeout stops the managed process and returns partial capped output plus timeout notice.
- Run cancellation stops the managed process promptly and returns partial output plus cancellation details/message.
- With fake prompt acceptance at the configured/default 30s threshold, the already-started command remains running under `BackgroundProcessManager` and the tool returns PID/log path.
- With fake prompt decline, command continues under foreground supervision until completion/timeout/cancellation.
- Accepting backgrounding does not start a duplicate command.
- Output is capped/persisted through `OutputCap`.
- Focused tests pass with Castor/PHPUnit.
- `castor check` is run before handoff unless environment prerequisites such as tmux or llama.cpp on port 9052 are unavailable; report exact blockers if so.

## Implementation notes
- Existing `ForegroundProcessRunner`, `ToolProcessRegistry`, and `ToolProcessTerminator` names in the original plan are stale; they are not present in the current codebase. Use the current `BackgroundProcessManager` + `ToolRuntime`/ambient cancellation context instead.
- `BackgroundProcessManager::start($command)` starts a new process, so it must be called at the beginning of execution, not after a foreground process has already been running.
- The supervisor loop should poll cheaply, avoid busy waiting, and always check cancellation/timeout before sleeping again.
- Use `readLogTail()` or an equivalent log read path carefully: final output should be capped by `OutputCap`; avoid loading unbounded logs into memory where possible.
- Logging must use structured event-style messages and must not include raw prompts, full command output, environment values, API keys, or full session content by default.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-09-bash-tool-background
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-09-bash-tool-background
Fork run: irp07mnx11h2
PR URL:
PR Status:
Started: 2026-05-31T18:06:23.023Z
Completed:

## Work log
- Created: 2026-05-17T04:42:49.755Z
- Updated: 2026-05-31 — Split production runtime/TUI confirmation bridge into TOOLS-09B. TOOLS-09 now starts every bash command through BackgroundProcessManager and supervises it as foreground to avoid duplicate execution/adoption problems.

## Task workflow update - 2026-05-31T18:06:23.023Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-09-bash-tool-background.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-09-bash-tool-background.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-09-bash-tool-background.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-09-bash-tool-background.

## Task workflow update - 2026-05-31T18:06:40.102Z
- Recorded fork run: dfc9tpb60hvm
- Launched implementation fork dfc9tpb60hvm in /home/ineersa/projects/agent-core-worktrees/tools-09-bash-tool-background.

## Task workflow update - 2026-05-31T18:17:36.390Z
- Recorded fork run: dfc9tpb60hvm
- Validation: castor test --filter=BashTool: PASS (18 tests, 56 assertions); castor test: PASS (1558 tests, 11584 assertions); castor deptrac: PASS (0 violations); castor phpstan scoped/all changed files: PASS (0 errors); castor cs-check: PASS; castor check: BLOCKED/FAIL — deptrac + unit/integration pass; ControllerSmokeTest fails on pre-existing log path resolution issue (`//agent-2026-05-31.log` with empty project_dir); llm-real/TUI E2E fails on pre-existing tmux pane issue (`%14 not found`). Per repo instructions, task remains IN-PROGRESS until full castor check can pass or blocker is resolved.
- Summary: Implementation fork completed TOOLS-09 and committed 94321e4d842e0b89778d47f93a74e122e65e6ecd on branch task/tools-09-bash-tool-background. Implemented BashTool as registry-backed Hatfield provider/handler; every command starts through BackgroundProcessManager::start() and is supervised foreground-style. Added BashToolConfig, default declining prompt adapter, settings/defaults, service wiring, and focused tests. Deferred TOOLS-09B runtime/TUI confirmation bridge and live streaming as planned.

## Task workflow update - 2026-06-05T00:45:35.424Z
- Recorded fork run: f9bcl8iphu1w
- Reviving stale TOOLS-09 branch after PHAR packaging landed on main. Current branch `task/tools-09-bash-tool-background` was at `94321e4d`, 249 commits behind `main`/`origin/main` (`a5e25bfe`) and 1 commit ahead, with diff vs main limited to the TOOLS-09 implementation files. Launched implementation fork `f9bcl8iphu1w` with model `deepseek/deepseek-v4-pro` to merge/rebase current main into the task branch, resolve any conflicts, inspect/fix drift against current BackgroundProcessManager/runtime APIs, run focused Castor validation (`castor test --filter=BashTool`, `castor test` if practical, `castor deptrac`, `castor phpstan`, `castor cs-check`), commit, and leave the worktree clean. Fork explicitly instructed not to push, not to open PR, and not to run full `castor check`.

## Task workflow update - 2026-06-05T00:58:15.521Z
- Recorded fork run: f9bcl8iphu1w
- Validation: Verified worktree status clean on branch `task/tools-09-bash-tool-background`.; Verified HEAD commit: `85eb8625 Merge origin/main into task/tools-09-bash-tool-background`.; Verified focused diff vs main: 9 files, 1066 insertions, 2 deletions (TOOLS-09 implementation only).; Fork validation reported: `composer install --prefer-dist --no-interaction` OK; `castor deptrac` OK (0 violations, 0 errors); `castor phpstan` OK (0 errors, 0 file_errors); `castor cs-check` OK (files_fixed=0); `castor test --filter=BashTool` OK (18 tests, 56 assertions); `castor test` OK (1677 tests, 4959 assertions).; Full `castor check` intentionally not run in implementation phase per workflow/user instructions.
- Summary: Revival fork `f9bcl8iphu1w` completed. Verified latest worktree HEAD `85eb8625` (`Merge origin/main into task/tools-09-bash-tool-background`) and clean worktree. The fork merged current `origin/main` into the stale task branch, resolved one `config/services.yaml` conflict, adapted TOOLS-09 to current main APIs (ORM-backed `ProcessStore`, `BackgroundProcess` entity, `BackgroundProcessStatusEnum`), and rewrote `BashToolTest` to use the kernel/container-backed ORM testing pattern. Diff vs current main remains focused on 9 TOOLS-09 files: `.hatfield/settings.yaml`, `config/hatfield.defaults.yaml`, `config/services.yaml`, `BashToolConfig`, `ToolsConfig`, prompt adapter interface/default adapter, `BashTool`, and `BashToolTest`. Worktree is clean; fork did not push or open PR.

## Task workflow update - 2026-06-05T01:11:44.622Z
- Recorded fork run: 4qczwp4pfffp
- User requested that the new `bash` tool not be included in real LLM tests, and proposed adding CLI parameters `--tools` and `--tools-excluded` with tests always passing `bash` excluded. Launched implementation fork `4qczwp4pfffp` with model `deepseek/deepseek-v4-pro` to add per-run tool filtering options, wire them into tool registry/toolbox exposure (not model-visible schema), make real LLM/controller/TUI subprocess tests exclude `bash` by default, add focused tests/docs as appropriate, run focused Castor validation, commit, and leave the worktree clean. Fork instructed not to push, open PR, or run full `castor check`.

## Task workflow update - 2026-06-05T01:22:06.566Z
- Recorded fork run: 4qczwp4pfffp
- Validation: Verified worktree status clean on branch `task/tools-09-bash-tool-background`.; Verified latest commit: `7b5a7dea TOOLS-09: Add --tools/--tools-excluded CLI options for tool filtering`.; Verified commit stat: 7 files changed, 324 insertions, 8 deletions (`AgentCommand`, `ToolRegistry`, `ToolRegistryInterface`, controller/TUI E2E test launch sites, `ToolRegistryTest`).; Fork validation reported: `castor test --filter=ToolRegistryTest` OK (45 tests, 101 assertions); `castor test --filter='BashToolTest|ToolRegistryTest|RegistryBackedToolboxTest'` OK (76 tests, 184 assertions); `castor deptrac` OK (0 violations, 0 errors); `castor phpstan` OK (0 errors, 0 file_errors); `castor cs-fix && castor cs-check` OK (2 files auto-fixed, then clean); `castor test` OK (1688 tests, 4984 assertions).; Full `LLM_MODE=true castor check` and real LLM/controller/TUI E2E intentionally not run in implementation phase; gate/reviewer workflow will handle that during task-to-pr.
- Summary: Implementation fork `4qczwp4pfffp` completed and committed `7b5a7dea` (`TOOLS-09: Add --tools/--tools-excluded CLI options for tool filtering`). Verified commit exists at worktree HEAD and worktree is clean. Added per-run CLI tool filtering via `AgentCommand` options `--tools` and `--tools-excluded`, registry-level allowlist/denylist methods on `ToolRegistryInterface`/`ToolRegistry`, and 11 `ToolRegistryTest` cases. Real LLM/controller/TUI subprocess-spawning tests now pass `--tools-excluded=bash` so the bash tool is not exposed during real-LLM E2E flows; PHAR smoke and in-process LlamaCpp smoke are intentionally unchanged. Diff vs current main now includes the TOOLS-09 bash implementation plus filtering changes: 16 files, 1390 insertions, 10 deletions.

## Task workflow update - 2026-06-05T01:33:50.593Z
- Recorded fork run: irp07mnx11h2
- Reviewer subagent returned REQUEST CHANGES on current HEAD `7b5a7dea`. Findings: critical PHPDoc corruption in `ToolRegistryInterface` around `activeToolNames()`/filter methods; critical cancellation path in `BashTool` bypassed `OutputCap`; plus actionable improvements around dead deadline null-check, dynamic background threshold wording, stopped-by-user ordering, possible targeted process lookup to avoid O(n) polling, and documenting raw shell execution intent. Launched implementation fork `irp07mnx11h2` with model `deepseek/deepseek-v4-pro` to address all sensible actionable findings, validate with focused Castor commands, commit, and leave worktree clean.
