---
name: tui-smoke-test
description: "Drive Hatfield through tmux for user-requested manual TUI smoke tests, including backgrounding, subagents, forks, cancellation, and bug snapshots."
---

# Hatfield TUI smoke testing

Use this procedure when the user asks you to operate Hatfield and observe real terminal behavior. Run the tests, not just a proposed checklist. Keep this a bounded manual investigation; do not build a new test harness or fix discovered bugs unless asked.

## Scope and prerequisites

- Read the checkout's `AGENTS.md`, `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, and the testing skill's `references/e2e.md` and `references/live-llm.md` before runtime work. Read the Castor skill before discovering launch tasks.
- For this user-requested manual workflow, use the default model and setup unless the user requests a specific model. The testing skill's instruction, "Use only `llama_cpp_test/test` on port 9052, never production providers in E2E," governs automated E2E runs; do not silently substitute that setup for an explicitly requested normal-setup manual test. Do not change automated E2E policy.
- Run directly in the current worktree. Task worktrees are disposable. Create a separate tmux session, not another checkout, isolated HOME, copied settings tree, or database setup.
- Separate session ownership still matters. Never interact with another session's panes or cancel its work. Never signal root-owned processes or processes tagged with `HATFIELD_SESSION_ID`.
- Preserve repository changes. Prefer read-only test prompts. Record `git status --short` before and after the run.
- Use bundled Hatfield docs for current keybindings and commands. Read `terminal-usage`, `background-processes`, and `agents` as needed.

## 1. Choose the cases

Turn the request into a short list of actions and observable outcomes. Start with the smallest useful set; expand when a failure needs isolation. Do not make the user approve every mechanical test choice.

For backgrounding and children, distinguish:

| Case | Observe |
|---|---|
| Background a subagent or fork | Parent becomes usable; the same child continues; one completion arrives. |
| Background Bash inside a child | Bash returns a background result; output eventually reaches the correct owner or parent. |
| Launch two children concurrently | Both appear running before either finishes; labels identify their results. |
| Background only one parallel child | Selected child backgrounds; its sibling remains foreground. |
| Mix a subagent and a fork | Both run concurrently and retain their identities. |
| Launch two forks | Both run concurrently and return separate results. |
| Stop child-owned background Bash | Correct PID stops; sibling work remains unaffected. |
| Cancel a backgrounded child | Child reaches cancelled state; no later success handoff. |

Test nested backgrounding separately: a foreground child with background Bash is not the same case as background Bash inside an already-backgrounded child.

Use unique case and output labels. Ask for new invocations when testing launch behavior; otherwise the model may resume an existing child. Keep child tasks limited to the requested command and output, with no repository inspection or edits. Follow ownership rules for parallel forks; give them distinct scratch directories when required.

Use a command such as `sleep 30; echo CASE_A; date --iso-8601=seconds`, with an explicit Bash timeout above its duration. A deliberate wait is justified here to expose the interactive Ctrl+B or cancellation window. It is not a readiness assertion or a pattern for automated regression tests. Choose the shortest practical duration and do not keep increasing it to hide failures.

## 2. Launch the normal setup

Discover current launchers with `castor list` and their help. Prefer `castor run:agent`, which preserves the normal model and setup. Do not choose `run:agent-test` merely because this is testing; it requests the local test model.

Create a uniquely named tmux session from the exact checkout and retain the returned pane ID. Make its window visible in the human's attached tmux session before testing; a detached-only session is not the default.

```bash
session="hatfield-smoke-$(date +%Y%m%d-%H%M%S)-$$"
pane=$(tmux new-session -d -P -F '#{pane_id}' \
	-s "$session" -x 160 -y 48 -c "$PWD" 'castor run:agent')
printf 'session=%s pane=%s\n' "$session" "$pane"
tmux list-sessions -F '#{session_name} attached=#{session_attached}'
```

Identify the human's attached session from the current tmux context or the session list. When there is one attached session, link the new test window into it without switching the human's active tab:

```bash
window=$(tmux display-message -p -t "$pane" '#{window_id}')
tmux link-window -d -s "$window" -t "$human_session:"
tmux rename-window -t "$window" hatfield-smoke-tests
```

Set `human_session` to the observed session name; do not assume it is `0`. If several attached sessions exist and ownership is unclear, ask which one to use. If none is attached, keep the test session detached and give the human `tmux attach-session -t "$session"` with the actual name.

Tell the human the visible tab name and index before sending test prompts. Keep the test window available for observation until cleanup. Link the same window rather than launching a second TUI.

Record the session, window, and pane IDs and target the pane explicitly in every command. Do not change global tmux options or switch the human's active tab. Linking can resize the window to the attached terminal; take fresh captures after layout changes.

Wait for positive TUI readiness, such as the welcome screen and idle prompt, using bounded captures that yield between checks. Stop early if the pane exits or shows a startup error. Do not use an arbitrary startup sleep.

Inspect the displayed model. Keep it unless the user requests a change. If requested, send `/model provider/model`, then verify the confirmation and footer before submitting a test. Named subagents can have their own configured model; report that distinction rather than rewriting their definitions.

## 3. Drive and observe

Send prompt text literally, then send Enter as a separate key:

```bash
tmux send-keys -t "$pane" -l '[case-01] Launch a NEW scout to run ...'
tmux send-keys -t "$pane" Enter
tmux capture-pane -p -t "$pane" -S -100
```

Use the current docs and visible UI instructions, not guessed key sequences:

| Operation | Interaction |
|---|---|
| Background active work | `tmux send-keys -t "$pane" C-b` |
| Select among concurrent invocations | Read the picker; use arrows and Enter to confirm the intended tool. |
| Enter a child | Submit `/agents-live`, inspect the picker, select the child, then verify the child footer. |
| Return to parent | Submit `/agents-main` or use the documented Ctrl+backslash binding. |
| Inspect or stop background work | Submit `/bg`, inspect ownership and PID, select the item, then confirm cancellation. |
| Cancel active work | Escape, after verifying that no picker or overlay will consume it instead. |
| Expand tool results | Ctrl+O, followed by a fresh capture to confirm the effect. |
| Exit the owned TUI | Ctrl+D after resolving remaining work. |

Wait for a running child card or active Bash command before pressing Ctrl+B. A streamed `subagent...` tool heading can precede execution and is not sufficient evidence that the background action took effect.

After each action, capture again and verify the transition. Key delivery alone is not a pass. Check child identity, status, parent responsiveness, output labels, and completion count. An idle parent or completed child card does not prove a child-owned background command finished successfully.

If `/bg` is refused in a child live view, return to the parent and inspect `/bg` there. Verify the row names the intended child and PID before stopping it. Distinguish this UI restriction from inability to cancel child-owned work.

Poll visible state only with a bounded deadline and a yielding interval. Use unique current-case markers so old scrollback cannot satisfy a predicate. Capture and report the last state on timeout; do not silently retry the whole scenario.

## 4. Preserve failure evidence

Create a unique directory under `.hatfield/tmp/tui-smoke/`. Save plain text, ANSI output, and enough scrollback to retain the triggering action:

```bash
artifacts=".hatfield/tmp/tui-smoke/$session"
mkdir -p "$artifacts"
tmux capture-pane -p -t "$pane" -S -500 > "$artifacts/case-01.txt"
tmux capture-pane -p -e -t "$pane" -S -500 > "$artifacts/case-01.ansi"
```

These are terminal snapshots, not pixel screenshots. Save evidence before navigating away, cancelling, or closing the pane. Capture the before and after states when selection or ownership matters.

Record the case, exact prompt or command, key sequence, expected result, actual result, model, pane and session IDs, child artifact/run IDs, and relevant PIDs. Treat transcripts as potentially sensitive; keep them local and do not dump unrelated session content or credentials into reports.

Inspect only the test's own evidence paths. Follow log and status paths shown in tool results, or inspect its canonical `events.jsonl`. Do not retrieve another parent session's artifacts through `agent_retrieve`; those identifiers are scoped to their parent.

When Bash reports an unknown outcome, distinguish:

- What the tool returned at that moment.
- Whether the command remained alive.
- Whether its log and exit-status file appeared later.
- Whether the parent received an eventual completion notification.

Do not call a command successful merely because a file eventually contains output, or call execution failed merely because supervision returned early. Preserve both observations and report the unverified contract. Investigate one discriminating follow-up rather than launching a large new matrix.

## 5. Clean up and report

1. Return to the parent view and inspect `/bg` for remaining test work.
2. Let bounded commands finish or cancel them through Hatfield's controls. Verify terminal outcomes and check known test PIDs without signalling unrelated processes.
3. Save final evidence and tell the human before closing the visible test tab. Exit the owned TUI through Ctrl+D and verify its pane closed. The linked tab closes with the same window; do not kill a shared tmux server or unrelated windows.
4. Check `git status --short` against the initial state. Leave snapshots local; do not commit session data or temporary artifacts.
5. Report a compact case table with passed, failed, blocked, or not tested outcomes. Separate observations from suspected causes. Include failure reproductions, evidence paths, model differences, and cleanup status.

A manual smoke pass is not the full QA gate. Do not claim automated coverage, all-case success, or a root cause that this run did not establish. If the user requests fixes or durable regression tests, switch to the normal implementation and validation workflow.
