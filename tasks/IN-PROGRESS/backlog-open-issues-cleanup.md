# Backlog cleanup: triage and fix open GitHub issues one-by-one

## Goal
Umbrella task to clean up current open GitHub issues one at a time. Process required by user: main agent acts only as orchestrator; avoid reading code directly except small targeted reads; use scouts for investigation/root-cause analysis; dispatch forks for implementation; after one issue is fixed, stop and ask user to validate before continuing; no code-review/reviewer phase.

Connected open issues as of 2026-06-12:
- #135 After resume session doesn't continue — https://github.com/ineersa/agent-core/issues/135
- #134 Tool-call/tool-result ordering error — https://github.com/ineersa/agent-core/issues/134
- #133 Errors on exit — https://github.com/ineersa/agent-core/issues/133
- #131 TUI is missing tool responses — https://github.com/ineersa/agent-core/issues/131
- #130 SafeGuard doesn't work properly — https://github.com/ineersa/agent-core/issues/130
- #129 vLLM as provider seems to be broken — https://github.com/ineersa/agent-core/issues/129
- #127 TUI on /resume is not re-rendered properly — https://github.com/ineersa/agent-core/issues/127
- #125 Compat flags in providers — https://github.com/ineersa/agent-core/issues/125
- #124 Read + parallel bug? — https://github.com/ineersa/agent-core/issues/124
- #123 Completion bug for @ files completions — https://github.com/ineersa/agent-core/issues/123
- #122 Token usage in footer incorrect — https://github.com/ineersa/agent-core/issues/122
- #121 After run was cancelled followup message not starting it back — https://github.com/ineersa/agent-core/issues/121
- #120 Thinking as editor border color — https://github.com/ineersa/agent-core/issues/120
- #119 CTRL+V and paste image — https://github.com/ineersa/agent-core/issues/119
- #118 Delay after submit — https://github.com/ineersa/agent-core/issues/118
- #117 Shift+Tab not showing thinking level chosen — https://github.com/ineersa/agent-core/issues/117

## Acceptance criteria
- Each connected issue is investigated by a scout before implementation.
- Implementation for each issue is done by a fork, not directly by the orchestrator.
- After each individual issue fix, pause and ask the user to validate before continuing to the next issue.
- For TUI/runtime changes, fork includes required TmuxHarness E2E proof and Castor validation per project rules.
- Task remains IN-PROGRESS during one-by-one cleanup until user explicitly asks to move forward.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/backlog-open-issues-cleanup
Worktree: /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup
Fork run: 7nz1rximl6ud
PR URL:
PR Status:
Started: 2026-06-12T16:54:23.580Z
Completed:

## Work log
- Created: 2026-06-12T16:54:16.266Z

## Task workflow update - 2026-06-12T16:54:23.580Z
- Moved TODO → IN-PROGRESS.
- Created branch task/backlog-open-issues-cleanup.
- Created worktree /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/backlog-open-issues-cleanup.
- Summary: Started umbrella backlog cleanup per user request. Will investigate and fix one GitHub issue at a time using scouts for root-cause analysis and forks for implementation, then stop for user validation after the first fix.

## Task workflow update - 2026-06-12T16:57:38.802Z
- Recorded fork run: 7nz1rximl6ud
- Started issue #117 first (oldest open issue): Shift+Tab not showing thinking level chosen.
- Scout completed root-cause analysis: footer intentionally hides reasoning text and tests enforce absence; Shift+Tab updates state without immediate footer invalidation; TUI E2E proof required.
- Fork 7nz1rximl6ud dispatched in worktree to implement only #117 with unit + TmuxHarness E2E validation.
