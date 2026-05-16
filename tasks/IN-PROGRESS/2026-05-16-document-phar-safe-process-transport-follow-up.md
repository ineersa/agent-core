# Document PHAR-safe process transport follow-up

## Goal
JsonlProcessAgentSessionClient currently assumes a source checkout and spawns bin/console from dirname(__DIR__, 4). For now, document this as a known limitation/TODO and outline the eventual self-executable/PHAR-safe process transport approach.

## Acceptance criteria
- JsonlProcessAgentSessionClient has a clear TODO/comment explaining why dirname(__DIR__, 4) and bin/console are source-checkout assumptions.
- A follow-up note exists in docs or task notes describing the future SelfExecutableLocator/process transport strategy for PHAR/binary distribution.
- No behavioral change required unless trivial and safe.
- castor check passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/2026-05-16-document-phar-safe-process-transport-follow-up
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up
Fork run: vrd2dxwnotxy
Started: 2026-05-16T17:49:06.561Z
Completed:

## Work log
- Created: 2026-05-16T01:22:20.671Z

## Task workflow update - 2026-05-16T17:49:06.561Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-document-phar-safe-process-transport-follow-up.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:39.770Z
- Recorded fork run: vrd2dxwnotxy
- Fork vrd2dxwnotxy launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up`.
