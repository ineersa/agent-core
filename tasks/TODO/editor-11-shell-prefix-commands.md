# EDITOR-11 Shell command prefix !

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes — deliberately small.

Implement a single editor submission prefix:

```text
!<command>
```

Submitting `!<command>` executes the command through the existing bash/tool/runtime path and shows the result in the transcript. It is a terminal convenience feature only: output is **not** added to the model context and submitting a shell command must **not** trigger an LLM turn.

This task supersedes the original `!`/`!!` context-vs-hidden split. The user explicitly chose simplicity because this feature is rarely used.

## Final product semantics

### Supported
- `!echo hello` runs bash command `echo hello`.
- The command and its output appear in the TUI transcript/status/tool block stream using existing runtime projection primitives where practical.
- Shell command execution does not call the LLM.
- Shell command output is not appended to `RunState::messages`, prompt context, user context, tool-role model history, or any other LLM-visible state.
- The first submitted input may be a shell command. This must create/use whatever runtime session state is needed to display/persist transcript output, but it must remain a shell-only action and must not invoke the model.
- If a normal agent turn is already working, submitted shell commands must queue/serialize through runtime command handling. Do not execute them directly in TUI and do not interleave concurrent shell output with the active turn.

### Not supported in this task
- `!!` execution is intentionally out of scope.
- No hidden/context mode split.
- No shell output injection into model context.
- No follow-up LLM turn after shell execution.
- No command history/search, shell completion, piping UI, or interactive shell session.

If the current parser still recognizes `!!`, implementation should make `!!` a clear local unsupported-command path (for example: “`!!` is not supported; use `!`”) and must not execute it. It is also acceptable to simplify parser/tests so only single-`!` shell commands are recognized, as long as `!!` is not silently executed.

## Existing code facts

Known current state before implementation:
- `src/Tui/Command/CommandParser.php` already has shell-prefix parsing support.
- `src/Tui/Command/ShellCommand.php` exists and currently models a command plus a hidden/context-related flag from the old `!`/`!!` design.
- `src/Tui/Command/SubmissionRouter.php` currently routes `ShellCommand` to a “not yet supported” local message/stub.
- `src/Tui/Command` has strict Deptrac boundaries and must remain pure TUI command parsing/routing code.
- TUI code must talk to runtime only through `src/CodingAgent/Runtime/Contract`, `src/CodingAgent/Runtime/Protocol`, and `AgentSessionClient`.

## Implementation direction

Prefer the smallest runtime-backed path that preserves boundaries:

1. **Parser / command DTO cleanup**
   - Keep `!<command>` parsing.
   - Reject empty commands (`!` or `!   `) with a local validation message.
   - Remove or ignore the old hidden/context flag for runtime behavior.
   - Ensure `!!` does not execute.

2. **Submission routing**
   - Replace the current shell-command stub in `SubmissionRouter` with a typed routing result, e.g. `DispatchShellCommand`.
   - The routed payload should include only:
     - command string
     - original submitted text if useful for transcript display
   - Do not add model-context flags; there is only one mode and it is context-excluded.

3. **TUI listener boundary**
   - Update submit handling (likely `SubmitListener`) to send a runtime command through `AgentSessionClient` rather than executing bash in TUI.
   - TUI must not import `AgentCore`, Messenger, BashTool, Symfony Process, or tool executor classes.

4. **Runtime contract/protocol**
   - Add a typed runtime/user command for shell execution, e.g. `shell_command`.
   - Update both runtime transports:
     - in-process client
     - JSONL/headless process client
   - Keep payload explicit and small: command text plus correlation/session metadata already used by runtime.

5. **Runtime/app execution**
   - Execute through the shared bash/tool path so existing bash semantics are reused:
     - cancellation where available
     - timeout/background behavior where available
     - approval/safety hooks where available
     - existing tool result formatting
   - Do not implement a second process runner in TUI or a parallel ad-hoc `proc_open`/`Process` path for this feature.
   - If a direct tool executor call is used, create a synthetic bash `ToolCall` in the app/runtime layer, not in `src/Tui`.

6. **Transcript projection**
   - Prefer emitting/reusing existing runtime/tool projection events so the transcript shows a normal tool-like block for bash.
   - Do not render shell output with one-off ad-hoc TUI strings except for local validation errors such as an empty command or unsupported `!!`.
   - Persist/replay shell transcript output consistently with existing session event/transcript mechanisms.

7. **Queueing / serialization**
   - While an agent turn is working, shell commands should be queued/serialized through runtime command handling.
   - Do not run shell commands in parallel from the focused editor listener.
   - Avoid transcript interleaving between an active LLM/tool turn and a submitted shell command.

## Likely files affected

This list is intentionally concrete for the implementor, but exact names may change after exploration:

- `src/Tui/Command/CommandParser.php`
- `src/Tui/Command/ShellCommand.php`
- `src/Tui/Command/SubmissionRouter.php`
- possible new `src/Tui/Command/DispatchShellCommand.php`
- `src/Tui/Listener/SubmitListener.php`
- `src/CodingAgent/Runtime/Contract/UserCommand.php`
- `src/CodingAgent/Runtime/Contract/AgentSessionClient.php` only if the existing `send()` shape is insufficient
- `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`
- `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`
- runtime protocol/controller command handling under `src/CodingAgent/Runtime/Protocol/` and `src/CodingAgent/Runtime/Controller/`
- app/runtime command handler that bridges shell command requests to the shared bash tool executor
- transcript/runtime projection code only if existing tool projection events cannot already represent the command output
- matching tests under `tests/Tui/`, `tests/CodingAgent/Runtime/`, and any AgentCore/app handler tests needed for context-exclusion

## Acceptance criteria

- `!echo hello` submitted from the editor runs the shared bash/tool execution path and displays `hello` in the transcript/tool output.
- Shell output is not included in model context and does not affect the next LLM prompt.
- Submitting `!echo hello` as the first input works without invoking the model.
- Submitting `!` or blank shell command produces a clear local validation message and does not execute anything.
- `!!` is not executed; it is either no longer parsed as a shell command or produces a clear unsupported-command message.
- Shell commands submitted while the agent is working are queued/serialized through runtime command handling, not run directly in parallel by TUI.
- TUI layer remains behind `AgentSessionClient` / runtime contract/protocol boundaries; Deptrac stays green.
- Tests cover parser/routing behavior, runtime command transport, successful command execution, no-LLM/no-context behavior, and unsupported/empty command behavior.
- At least one integration or TUI/runtime smoke test covers a successful shell command.

## Exclusions

- Do not implement bash process management here; reuse the existing BashTool/tool execution path.
- Do not bypass safety/approval hooks when they exist.
- Do not add shell output to model context.
- Do not implement `!!` semantics.
- Do not trigger an LLM turn after shell execution.
- Do not add external shell completion/history features.
- Do not add production APIs solely for tests.

Dependencies: EDITOR-03, EDITOR-05, TOOLS-09, RTVS-04, RTVS-07.
Parallelizable with: EDITOR-08, EDITOR-09, EDITOR-10 once dependencies allow.

## Validation

Use Castor only. Because this touches TUI/runtime/tool flow, full validation is required before CODE-REVIEW:

```bash
castor test --filter=CommandParser
castor test --filter=SubmissionRouter
castor test --filter=SubmitListener
castor test --filter=Shell
castor deptrac
castor phpstan
castor cs-check
LLM_MODE=true castor check
```

If prerequisites for `LLM_MODE=true castor check` are unavailable (tmux or llama.cpp test server on port 9052), the task must stay IN-PROGRESS with the blocker recorded.

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
- Created: 2026-05-18T00:16:30.829Z
- Updated: 2026-06-09 — simplified per user decision to a single `!` shell execution mode with transcript output only and no model-context injection.
