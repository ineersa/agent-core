# TOOLS-09 Implement bash tool with OutputCap and user-controlled backgrounding

## Goal
Implement the `bash` tool with foreground execution, output capping, and user-controlled backgrounding.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-02 (`OutputCap`).
- Depends on TOOLS-08 (`BackgroundProcessManager`).

Scope:
- Replace/complete `src/CodingAgent/Tool/BashTool.php`.
- Register with `#[AsTool('bash', description: 'Execute a bash command')]`.
- Schema should be derived from `__invoke(string $command, ?int $timeout = null)`.
- Do NOT add `run_in_background` or any model-controlled background parameter.
- Execute commands through Symfony Process using configured shell (`bash` on Unix, fallback to `sh`) and project cwd.
- Capture stdout+stderr and pass text through `OutputCap`.
- Non-zero exit appends/reports `Exit code N` while still returning output.
- Timeout kills process and returns partial output plus timeout message.
- User-controlled backgrounding behavior:
  - At 30 seconds of runtime, proactively prompt the TUI/user: `Command still running after 30s. Move to background?`.
  - If timeout is less than 30 seconds and reached first, do normal timeout kill without a background prompt.
  - If user accepts, register process with `BackgroundProcessManager`, stream remaining output to `.hatfield/tmp/bg/`, and return `Moved to background. PID: N, Log: <path>` to the model.
  - If user declines, keep running until timeout or completion.
- If the project does not yet expose a direct TUI confirm service to tools, implement the foreground behavior and add a small injectable interface/adapter for the prompt with a non-interactive default that declines; document where the TUI should wire confirmation.
- Add focused tests using fake prompt adapter and short shell commands.

Out of scope:
- No sandbox/allowlist.
- No model-controlled backgrounding.
- No ANSI/binary output sanitization unless already trivial.

## Acceptance criteria
- `bash` tool is discoverable through Symfony AI toolbox metadata with only `command` and optional `timeout` parameters.
- Foreground successful command returns captured output.
- Non-zero command returns output plus exit code information.
- Timeout kills the process and returns partial output plus timeout notice.
- With fake prompt acceptance at the 30s threshold, command is registered in `BackgroundProcessManager` and tool returns PID/log path.
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
