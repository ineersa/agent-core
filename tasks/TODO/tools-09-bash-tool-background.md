# TOOLS-09 Implement bash tool with OutputCap and user-controlled backgrounding

## Goal
Implement the `bash` tool with foreground execution, output capping, and user-controlled backgrounding.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox, settings, and allowlist wiring).
- Depends on TOOLS-00 (`ToolExecutionContextInterface`, `ForegroundProcessRunner`, `ToolProcessRegistry`, `ToolProcessTerminator`).
- Depends on TOOLS-02 (`OutputCap`).
- Depends on TOOLS-08 (`BackgroundProcessManager`).

Scope:
- Replace/complete `src/CodingAgent/Tool/BashTool.php`.
- Provide a Hatfield tool definition/provider for `bash` instead of relying on `#[AsTool]` metadata.
- Register `bash` as a permanent tool through the TOOLS-R02 built-in tool registrar/`ToolRegistryInterface`, including provider description, explicit JSON schema, prompt line, and concise guidelines. Execution flows through the TOOLS-R03 registry-backed Toolbox.
- Tool definition JSON schema should match `__invoke(string $command, ?int $timeout = null)`.
- Do NOT add `run_in_background` or any model-controlled background parameter.
- Execute foreground commands through `ForegroundProcessRunner` using configured shell (`bash` on Unix, fallback to `sh`) and project cwd.
- Read default timeout, background prompt threshold, output caps, and process termination grace from Hatfield tool settings introduced by TOOLS-R04; user-provided `timeout` can override within safe bounds.
- Capture stdout+stderr and pass text through `OutputCap`.
- Non-zero exit appends/reports `Exit code N` while still returning output.
- Timeout kills the registered foreground process through `ToolProcessTerminator` TERM -> grace -> KILL semantics and returns partial output plus timeout message.
- Run cancellation while foreground bash is running kills the process promptly through the TOOLS-00 controller/registry/terminator path and returns structured `cancelled=true` details rather than a generic tool failure.
- User-controlled backgrounding behavior through the runner-level observer/detach hook from TOOLS-00, transferring ownership from `ForegroundProcessRunner` to `BackgroundProcessManager` when the user accepts:
  - At the settings-backed background prompt threshold (default 30 seconds), proactively prompt the TUI/user: `Command still running after 30s. Move to background?`.
  - If timeout is less than the background prompt threshold and reached first, do normal timeout kill without a background prompt.
  - If user accepts, register process with `BackgroundProcessManager`, stream remaining output to `.hatfield/tmp/bg/`, and return `Moved to background. PID: N, Log: <path>` to the model.
  - If user declines, keep running until timeout or completion.
- If the project does not yet expose a direct TUI confirm service to tools, implement the foreground behavior and add a small injectable interface/adapter for the prompt with a non-interactive default that declines; document where the TUI should wire confirmation.
- Add focused tests using fake prompt adapter and short shell commands.

Out of scope:
- No sandbox/allowlist.
- No model-controlled backgrounding.
- No ANSI/binary output sanitization unless already trivial.

## Acceptance criteria
- `bash` tool is discoverable through registry-backed Symfony Toolbox metadata with only `command` and optional `timeout` parameters, and present in `ToolRegistryInterface` permanent metadata.
- Foreground successful command returns captured output.
- Non-zero command returns output plus exit code information.
- Timeout kills the process and returns partial output plus timeout notice.
- Run cancellation kills foreground bash promptly via the TOOLS-00 foreground process registry/terminator path, includes partial output where available, and marks the result as cancelled.
- With fake prompt acceptance at the configured/default 30s threshold, command is registered in `BackgroundProcessManager` through the runner-level detach handoff and tool returns PID/log path.
- With fake prompt decline, command continues until completion/timeout.
- Output is capped/persisted through `OutputCap`.
- Focused tests pass with Castor/PHPUnit.

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
- Created: 2026-05-17T04:42:49.755Z
