# MAINT-05E Rework TUI E2E into replay-backed journey tests

## Goal
## Context

Fifth stage of the cardinal QA/test rework. TUI E2E should stop launching a new tmux/TUI process for every tiny assertion. Most TUI behavior should be proven by a small number of long-lived journey tests, using deterministic LLM replay where model output is needed.

Current problem:
- Too many TUI E2E tests each launch tmux and wait through startup/runtime/model paths.
- Repeated launches make tests slow, flaky, and hard to reason about.
- Some tests use fragile patterns such as `exec sleep 10`.

Dependencies:
- Prefer after MAINT-05C replay foundation.
- Some process ownership assumptions may rely on MAINT-05D.

Known entrypoints:
- `tests/Tui/E2E/TmuxHarness.php`
- `tests/Tui/E2E/TuiAgentSmokeTest.php`
- `tests/Tui/E2E/TuiStartupSnapshotTest.php`
- `tests/Tui/E2E/HotkeySmokeTest.php`
- `tests/Tui/E2E/ImmediateSubmitFeedbackTest.php`
- `tests/Tui/E2E/ShellPrefixSmokeTest.php`
- `tests/Tui/E2E/EditorBorderColorTest.php`
- `tests/Tui/E2E/ReasoningCycleTest.php`
- `tests/Tui/E2E/SessionRenameE2ETest.php`
- `tests/Tui/E2E/PromptTemplateSlashCommandE2ETest.php`

## Acceptance criteria
- Default TUI E2E is organized as a small number of journey tests that reuse a long-lived tmux/TUI session for multiple assertions.
- Separate tmux launches remain only for behavior that explicitly requires process start/end/resume/relaunch isolation.
- TUI tests that need model output use deterministic replay fixtures, not live llama.cpp, in the default suite.
- UI-only behaviors are grouped into one or a few journeys: startup layout, editor keys, hotkeys, reasoning cycling, border/status state, rename, slash commands, shell-prefix local validation where appropriate.
- Overlapping smoke tests are consolidated; assertions are preserved at behavior level but not duplicated across many process launches.
- `exec sleep 10` and similar fixed process-holding patterns are removed. Fixed sleeps are not added except where timing itself is the behavior under test.
- TmuxHarness exposes journey-friendly helpers/steps and reliable teardown; tests leave no tmux sessions or child processes behind on failure.
- The task records before/after TUI harness launch count and wall time.
- Validation uses Castor only: `castor test:tui`, relevant replay/controller tests if needed, `castor deptrac`, `castor phpstan`, `castor cs-check`, and default deterministic check if available.
- Docs/skills are updated so future TUI tests follow the journey model instead of one-harness-per-assertion.

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
- Created: 2026-06-15T21:07:56.576Z
