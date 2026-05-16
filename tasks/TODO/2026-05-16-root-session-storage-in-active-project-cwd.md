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
Status: TODO
Branch:
Worktree:
Fork run:
Started:
Completed:

## Work log
- Created: 2026-05-16T01:22:15.792Z
