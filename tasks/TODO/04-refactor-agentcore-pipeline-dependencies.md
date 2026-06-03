# 04-refactor-agentcore-pipeline-dependencies: remove shallow state tools facade

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/agent-core-architecture.md

Remove the shallow RunMessageStateTools facade and give each pipeline handler only the focused collaborators it actually uses. Use this task to also improve the AgentMessage/Symfony AI conversion boundary where it naturally falls out of the facade removal.

Scope:
- Delete or retire RunMessageStateTools.
- Inject EventFactory, ToolCallExtractor, and message conversion/normalization services directly where needed.
- Move AgentMessageNormalizer out of Domain if feasible in this task; otherwise leave a small follow-up note for remaining Symfony AI coupling.
- Update handler tests to construct focused dependencies.

## Acceptance criteria
- Pipeline handlers no longer depend on RunMessageStateTools.
- Each handler constructor depends on focused services actually used by that handler.
- AgentMessageNormalizer no longer lives in Domain, or the task documents the remaining Domain/Symfony AI coupling and why it is deferred.
- Run and report Castor validation: castor test --filter for affected pipeline handlers, castor deptrac, and castor check, or exact environmental blockers.

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
- Created: 2026-06-03T00:31:44.294Z
