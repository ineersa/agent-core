# Fix Castor hard timeouts for E2E process trees

## Goal
Follow-up from PT-03 post-merge validation: `LLM_MODE=true castor check` was manually cancelled after ~300s even though check steps have 30/60/75s timeout wrappers. Evidence: orphaned PHAR `messenger:consume` grandchildren from `/home/ineersa/projects/agent-core/var/tmp/phar/hatfield.phar` survived with PPID systemd user (2060) and stdout/stderr pipes inherited from PHPUnit/controller processes. Castor parent likely blocked draining stdout/stderr because leaked grandchildren kept pipes open after the direct timed command exited. Need hard process-tree/process-group termination for Castor parallel steps and reliable nonblocking pipe draining.

## Acceptance criteria
- Castor check step timeouts hard-stop the entire process tree/process group, not just the immediate shell/PHPUnit process.
- Castor parallel runner cannot hang forever in `stream_get_contents()` when orphaned grandchildren keep stdout/stderr pipes open.
- On timed-out steps, Castor records a clear timeout failure including step name and elapsed timeout.
- Controller/TUI/llm-real PHAR messenger consumers are not left behind after timed-out or failed `castor check` steps.
- Validation includes a targeted reproduction/smoke proving a timed command that leaks a child holding stdout open returns within the configured timeout, plus `LLM_MODE=true castor check` or a justified subset including `castor test:tui`.

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
- Created: 2026-06-12T22:51:42.900Z
