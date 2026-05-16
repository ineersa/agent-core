# Root session storage in active project cwd

## Goal
Session persistence should use the user project cwd/session config, not the app install root or Symfony kernel.project_dir. Review HatfieldSessionStore, SessionRunStore, SessionRunEventStore, and runtime wiring so .hatfield/sessions lives under the target project unless settings override it.

## Acceptance criteria
- SessionRunStore and SessionRunEventStore no longer implicitly write to app install root for TUI/headless runs.
- The active project cwd is passed through runtime/session boundaries explicitly enough to support PHAR distribution.
- HatfieldSessionStore and AgentCore stores agree on the same session directory for a run.
- Resume flow still works with session_id === run_id.
- Relevant session-storage docs/tests are updated.
- castor check passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/2026-05-16-root-session-storage-in-active-project-cwd
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd
Fork run: cozv1blzbi34
Started: 2026-05-16T17:49:44.465Z
Completed:

## Work log
- Created: 2026-05-16T01:22:15.792Z

## Task workflow update - 2026-05-16T17:49:44.465Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-root-session-storage-in-active-project-cwd.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:39.771Z
- Recorded fork run: cozv1blzbi34
- Fork cozv1blzbi34 launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd`.
