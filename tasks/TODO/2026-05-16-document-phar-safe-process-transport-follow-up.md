# Document PHAR-safe process transport follow-up

## Goal
JsonlProcessAgentSessionClient currently assumes a source checkout and spawns bin/console from dirname(__DIR__, 4). For now, document this as a known limitation/TODO and outline the eventual self-executable/PHAR-safe process transport approach.

## Acceptance criteria
- JsonlProcessAgentSessionClient has a clear TODO/comment explaining why dirname(__DIR__, 4) and bin/console are source-checkout assumptions.
- A follow-up note exists in docs or task notes describing the future SelfExecutableLocator/process transport strategy for PHAR/binary distribution.
- No behavioral change required unless trivial and safe.
- castor check passes.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
Started:
Completed:

## Work log
- Created: 2026-05-16T01:22:20.671Z
