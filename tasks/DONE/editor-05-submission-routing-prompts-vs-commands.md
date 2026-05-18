# EDITOR-05 Submission routing for prompts vs commands

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Integrate command parsing/registry into the TUI submit path.
- Route normal prompt submissions to the runtime exactly as before.
- Route slash command submissions to the local command executor.
- Apply built-in command effects: help transcript/status message, clear transcript, exit app.
- Ensure command submissions are handled consistently with prompt clearing/history hooks.

Exclusions:
- No prompt history persistence; EDITOR-07 owns history.
- No slash completion UI; EDITOR-08 owns completion.
- No !/!! shell execution; EDITOR-11 owns shell prefixes.
- Do not import AgentCore internals into Tui.

Dependencies: EDITOR-02, EDITOR-04.
Parallelizable with: none after dependencies; serialize InteractiveMode/input routing edits.

## Acceptance criteria
- Normal prompts still submit to AgentSessionClient/runtime path.
- /help, /clear, and /exit work from the editor submit path.
- Slash commands do not create user prompt runtime events unless the command explicitly dispatches a runtime command.
- Unknown commands render a friendly local message/status.
- Composer/editor state is cleared or preserved according to command result semantics and covered by tests.
- castor deptrac passes.

## Workflow metadata
Status: DONE
Branch: task/editor-05-submission-routing-prompts-vs-commands
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands
Fork run: hc0ewcxbrjlg
PR URL: https://github.com/ineersa/agent-core/pull/26
PR Status: merged
Started: 2026-05-18T17:52:40.003Z
Completed: 2026-05-18T21:34:13.253Z

## Work log
- Created: 2026-05-18T00:15:36.255Z

## Task workflow update - 2026-05-18T17:52:40.003Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-05-submission-routing-prompts-vs-commands.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.

## Task workflow update - 2026-05-18T17:52:59.013Z
- Recorded fork run: hc0ewcxbrjlg
- Summary: Launched background fork to implement EDITOR-05 in worktree /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.

## Task workflow update - 2026-05-18T20:33:12.440Z
- Recorded fork run: hc0ewcxbrjlg
- Validation: vendor/bin/phpunit tests/Tui/Command/SubmissionRouterTest.php — 18 tests, 29 assertions, OK; vendor/bin/phpunit tests/Tui/Command/ — 72 tests, 162 assertions, OK; ~/.local/bin/castor test — 469 tests, 8348 assertions, OK (1 pre-existing notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: EDITOR-05 fork completed. Implemented SubmissionRouter integrating CommandParser + SlashCommandRegistry, wired SubmitListener to route slash commands locally, apply TranscriptMessage/ClearTranscript/ExitApplication/StatusUpdate/NoOp effects, and preserve normal prompt runtime path. ShellCommand returns local not-supported TranscriptMessage for now. Commit 7e38c8a7. Fork reports validation clean.

## Task workflow update - 2026-05-18T20:33:29.717Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-05-submission-routing-prompts-vs-commands to origin.
- branch 'task/editor-05-submission-routing-prompts-vs-commands' set up to track 'origin/task/editor-05-submission-routing-prompts-vs-commands'.
- Created PR: https://github.com/ineersa/agent-core/pull/26
- Validation: vendor/bin/phpunit tests/Tui/Command/SubmissionRouterTest.php — 18 tests, 29 assertions, OK; vendor/bin/phpunit tests/Tui/Command/ — 72 tests, 162 assertions, OK; ~/.local/bin/castor test — 469 tests, 8348 assertions, OK (1 pre-existing notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: EDITOR-05 completed in fork hc0ewcxbrjlg. Added SubmissionRouter for CommandParser + SlashCommandRegistry, wired SubmitListener to route slash commands locally while preserving normal prompt runtime path, and applied built-in command effects for TranscriptMessage, ClearTranscript, ExitApplication, StatusUpdate, and NoOp. Shell prefixes return local not-supported message pending EDITOR-11.

## Task workflow update - 2026-05-18T21:34:13.253Z
- Moved CODE-REVIEW → DONE.
- Merged task/editor-05-submission-routing-prompts-vs-commands into integration checkout.
- Merge made by the 'ort' strategy.
 depfile.yaml                               |   1 +
 src/Tui/Command/SubmissionRouter.php       |  55 ++++++++
 src/Tui/Listener/SubmitListener.php        |  89 ++++++++++++-
 tests/Tui/Command/SubmissionRouterTest.php | 197 +++++++++++++++++++++++++++++
 4 files changed, 341 insertions(+), 1 deletion(-)
 create mode 100644 src/Tui/Command/SubmissionRouter.php
 create mode 100644 tests/Tui/Command/SubmissionRouterTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #26 merged: https://github.com/ineersa/agent-core/pull/26
- Summary: PR #26 merged by user. EDITOR-05 complete: normal prompts route to runtime, slash commands route locally through SlashCommandRegistry, /help /clear /exit effects apply in submit path, shell prefixes show local not-supported message pending EDITOR-11.
