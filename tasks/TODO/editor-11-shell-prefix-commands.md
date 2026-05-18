# EDITOR-11 Shell command prefixes ! and !!

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Route submitted ! and !! prefixes through application command handling.
- ! runs bash and includes output in context/transcript according to available tool/runtime APIs.
- !! runs bash but does not include output in model context, only transcript/status as appropriate.
- Render bash output as transcript/tool blocks using runtime transcript projection primitives.
- Reuse bash tool/background/cancellation semantics instead of implementing a second process runner.

Exclusions:
- Do not implement bash process management here; TOOLS-09 owns bash tool behavior.
- Do not implement transcript block primitives; RTVS tasks own runtime transcript projection.
- Do not bypass safety/approval hooks when they exist.

Dependencies: EDITOR-03, EDITOR-05, TOOLS-09, RTVS-04, RTVS-07.
Parallelizable with: EDITOR-08, EDITOR-09, EDITOR-10 once dependencies allow.

## Acceptance criteria
- ! and !! are parsed from submitted editor text and routed as app commands, not normal prompts.
- Bash execution uses the shared bash/tool path and cancellation/background semantics.
- ! includes output in model context when supported; !! does not.
- Transcript/tool block output appears through runtime projection rather than ad-hoc rendered strings.
- Tests cover prefix parsing and routing; integration/smoke covers at least one successful command.
- castor deptrac passes.

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
- Created: 2026-05-18T00:16:30.829Z
