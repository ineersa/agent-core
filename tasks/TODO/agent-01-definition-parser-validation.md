# AGENT-01 Agent definition parser and validation

## Goal
Create the production-ready, standalone agent definition parsing layer. This is intentionally limited to reading/parsing/validating one agent definition file and typed DTOs; do not implement launching, hidden runs, artifacts, TUI, MCP execution, or compatibility/fallback paths.

Context:
- Reference plan: `.pi/plans/agents-subagents-implementation-plan.md`.
- Reuse patterns from existing settings/skills/frontmatter parsing where appropriate.
- Supported definition file shape is Markdown with YAML frontmatter and body instructions.
- `.agents/` support is first-class; discovery/catalog precedence is handled by AGENT-02, not this task.
- No backward-compatibility shims or dual-format readers unless explicitly requested.

Suggested scope:
- `AgentDefinitionDTO` and related typed value objects/enums.
- `AgentDefinitionParser` / `AgentFrontmatterParser` for a single file.
- Validation errors with actionable file/path/field messages.
- Fields should cover at least: `name`, `description`, `type`, `tools`, `mcpTools`/MCP access shape, `model`, `thinking`, `skills`, inheritance flags, `maxDepth`, `backgroundAllowed`, `foregroundAllowed`, `parallelAllowed`, `disabled`, and body prompt/instructions.
- Keep implementation in the app layer; do not add AgentCore or TUI dependencies.

## Acceptance criteria
- A single agent definition Markdown file can be parsed into typed DTOs with body prompt preserved.
- Invalid/missing frontmatter fields produce actionable validation errors including file path and field name.
- Unit/integration coverage exercises valid definitions and representative invalid definitions without testing trivial getters only.
- No launch/runtime/TUI/MCP execution behavior is added.
- No fallback/compatibility/legacy dual-format code is added.
- Docs or task notes identify the exact frontmatter schema AGENT-02 should consume.
- Validation uses Castor commands only, with at least focused `castor test --filter=...`, plus `castor phpstan`/`castor deptrac` if relevant.

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
- Created: 2026-06-15T22:52:05.370Z
