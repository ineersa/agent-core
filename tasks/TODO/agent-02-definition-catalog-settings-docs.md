# AGENT-02 Agent definition catalog, discovery, settings, and docs

## Goal
Build the production agent definition catalog/registry on top of AGENT-01. This task is about locating, loading, validating, and listing definitions. It must not implement agent execution, hidden child runs, artifacts, or TUI controls.

Context:
- Depends on AGENT-01.
- Reference plan: `.pi/plans/agents-subagents-implementation-plan.md`.
- Discovery must support Hatfield-native and cross-tool agent locations.
- `.agents/` support is first-class, not a legacy fallback.
- Avoid compatibility/fallback layers; document and enforce one deterministic precedence model.

Required discovery precedence to confirm/implement/document:
1. builtin agents bundled with Hatfield
2. user agents under `~/.hatfield/agents/`
3. user agents under `~/.agents/`
4. project agents under `.hatfield/agents/`
5. project agents under `.agents/`

Suggested scope:
- `AgentDefinitionCatalog` / registry service.
- Discovery loaders for builtin/user/project locations.
- Settings keys for enabling/disabling agents and configuring definition directories if needed.
- Deterministic override behavior by agent name.
- Disabled definitions are reported/listed appropriately but not launchable later.
- Initial builtin definition files for `scout`, `reviewer`, `researcher`, and `worker` may be added if they can be validated by the catalog, but no execution behavior should be wired.

## Acceptance criteria
- Catalog can discover and validate definitions from builtin, user, and project locations using deterministic documented precedence.
- Project definitions override user definitions by name; user/project `.agents/` locations are first-class.
- Duplicate/invalid definitions produce actionable diagnostics without silently falling back to another format.
- Settings changes, if any, are reflected in `.hatfield/settings.yaml` and `docs/settings.md`.
- `docs/agents.md` documents definition format, discovery paths, precedence, and disabled definitions.
- No agent launch/runtime/TUI/artifact behavior is implemented.
- Validation uses Castor commands only, with focused catalog/parser tests and `castor phpstan`/`castor deptrac` as relevant.

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
- Created: 2026-06-15T22:52:17.143Z
