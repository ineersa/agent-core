# EDITOR-04 MVP slash command registry and built-in commands

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Add a small slash command registry/executor.
- Implement /help, /clear, and /exit.
- Expose command metadata: name, aliases, one-line description, and usage text for /help and future completion.
- Provide an extension seam so later AI/model tasks can add commands such as /model without rewriting editor/submission routing.

Exclusions:
- No InteractiveMode routing; EDITOR-05 owns invoking the registry from submitted text.
- No slash completion menu; EDITOR-08 owns completion.
- No /model implementation unless already explicitly requested by an AI/model task.
- No shell command execution.

Dependencies: EDITOR-03.
Parallelizable with: EDITOR-02.

## Acceptance criteria
- Registry can register and look up slash commands by name and aliases.
- /help returns command help using registry metadata.
- /clear and /exit return typed effects that the application layer can apply.
- Unknown command returns a friendly typed error/result, not an exception path for normal user input.
- Tests cover registration, aliases, built-ins, help output, and unknown command behavior.
- castor deptrac passes.

## Workflow metadata
Status: DONE
Branch: task/editor-04-mvp-slash-commands
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands
Fork run: o4ms61xx0xia
PR URL: https://github.com/ineersa/agent-core/pull/25
PR Status: merged
Started: 2026-05-18T17:10:23.254Z
Completed: 2026-05-18T17:52:24.887Z

## Work log
- Created: 2026-05-18T00:15:27.554Z

## Task workflow update - 2026-05-18T17:10:23.254Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-04-mvp-slash-commands.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.

## Task workflow update - 2026-05-18T17:11:25.718Z
- Recorded fork run: o4ms61xx0xia
- Summary: Launched background fork to implement EDITOR-04 in worktree /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.

## Task workflow update - 2026-05-18T17:31:22.970Z
- Recorded fork run: o4ms61xx0xia
- Validation: vendor/bin/phpunit tests/Tui/Command/ — 54 tests, 133 assertions, OK; ~/.local/bin/castor test — 451 tests, 8319 assertions, OK (1 unrelated PHPUnit notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: EDITOR-04 fork completed. Implemented SlashCommandRegistry, CommandMetadata, SlashCommandHandler, built-ins /help, /clear, /exit, friendly unknown command handling, metadata APIs for future completion, register/setHandler extension seam, and deterministic command tests. Commit 763466eb. Fork reports validation clean: castor test/deptrac/phpstan/cs-check all pass.

## Task workflow update - 2026-05-18T17:31:44.066Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-04-mvp-slash-commands to origin.
- branch 'task/editor-04-mvp-slash-commands' set up to track 'origin/task/editor-04-mvp-slash-commands'.
- Created PR: https://github.com/ineersa/agent-core/pull/25
- Validation: vendor/bin/phpunit tests/Tui/Command/ — 54 tests, 133 assertions, OK; ~/.local/bin/castor test — 451 tests, 8319 assertions, OK (1 unrelated PHPUnit notice); ~/.local/bin/castor deptrac — 0 violations; ~/.local/bin/castor phpstan — 0 errors; ~/.local/bin/castor cs-check — clean
- Summary: EDITOR-04 completed in fork o4ms61xx0xia. Added SlashCommandRegistry, CommandMetadata, SlashCommandHandler, built-in /help /clear /exit commands, friendly unknown-command TranscriptMessage handling, aliases/metadata APIs, and extension seam via register()/setHandler(). No InteractiveMode routing added; EDITOR-05 owns consumption.

## Task workflow update - 2026-05-18T17:52:24.887Z
- Moved CODE-REVIEW → DONE.
- Merged task/editor-04-mvp-slash-commands into integration checkout.
- Merge made by the 'ort' strategy.
 src/Tui/Command/ClearScreenCommand.php         |  16 +
 src/Tui/Command/ClearTranscript.php            |   4 +-
 src/Tui/Command/CommandMetadata.php            |  29 ++
 src/Tui/Command/CommandParser.php              |   6 +-
 src/Tui/Command/CommandResult.php              |   4 +-
 src/Tui/Command/DispatchRuntime.php            |   3 +-
 src/Tui/Command/ExitApplication.php            |   4 +-
 src/Tui/Command/ExitTuiCommand.php             |  16 +
 src/Tui/Command/NoOp.php                       |   4 +-
 src/Tui/Command/NormalPrompt.php               |   3 +-
 src/Tui/Command/ShellCommand.php               |   3 +-
 src/Tui/Command/SlashCommand.php               |   3 +-
 src/Tui/Command/SlashCommandHandler.php        |  23 ++
 src/Tui/Command/SlashCommandRegistry.php       | 321 ++++++++++++++++++
 src/Tui/Command/StatusUpdate.php               |   3 +-
 src/Tui/Command/TranscriptMessage.php          |   3 +-
 tests/Tui/Command/EchoHandler.php              |  24 ++
 tests/Tui/Command/FixedMessageTestHandler.php  |  25 ++
 tests/Tui/Command/NoOpTestHandler.php          |  21 ++
 tests/Tui/Command/SlashCommandRegistryTest.php | 434 +++++++++++++++++++++++++
 20 files changed, 936 insertions(+), 13 deletions(-)
 create mode 100644 src/Tui/Command/ClearScreenCommand.php
 create mode 100644 src/Tui/Command/CommandMetadata.php
 create mode 100644 src/Tui/Command/ExitTuiCommand.php
 create mode 100644 src/Tui/Command/SlashCommandHandler.php
 create mode 100644 src/Tui/Command/SlashCommandRegistry.php
 create mode 100644 tests/Tui/Command/EchoHandler.php
 create mode 100644 tests/Tui/Command/FixedMessageTestHandler.php
 create mode 100644 tests/Tui/Command/NoOpTestHandler.php
 create mode 100644 tests/Tui/Command/SlashCommandRegistryTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #25 merged: https://github.com/ineersa/agent-core/pull/25
- Summary: PR #25 merged by user. EDITOR-04 complete: slash command registry/executor, metadata, built-in /help /clear /exit, aliases, extension seam, and friendly unknown-command behavior.
