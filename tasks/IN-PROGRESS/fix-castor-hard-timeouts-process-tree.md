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
Status: IN-PROGRESS
Branch: task/fix-castor-hard-timeouts-process-tree
Worktree: /home/ineersa/projects/agent-core-worktrees/fix-castor-hard-timeouts-process-tree
Fork run:
PR URL:
PR Status:
Started: 2026-06-12T22:51:50.812Z
Completed:

## Work log
- Created: 2026-06-12T22:51:42.900Z

## Task workflow update - 2026-06-12T22:51:50.812Z
- Moved TODO → IN-PROGRESS.
- Created branch task/fix-castor-hard-timeouts-process-tree.
- Created worktree /home/ineersa/projects/agent-core-worktrees/fix-castor-hard-timeouts-process-tree.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/fix-castor-hard-timeouts-process-tree.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/fix-castor-hard-timeouts-process-tree.
- Validation: Observed stale integration PHAR messenger consumers adopted by systemd user with fds to PHPUnit/controller pipes after cancelled post-merge castor check.; Observed retry with outer timeout 180s reports test:tui-1 exit 124 at 60.0s, proving per-step timeout fires but does not guarantee clean process-tree/pipes semantics.
- Summary: Starting urgent follow-up to fix Castor timeout/process-tree cleanup after PT-03 post-merge check hung despite per-step timeout wrappers.
