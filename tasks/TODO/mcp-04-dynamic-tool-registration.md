# MCP-04 Dynamic MCP tool registration from catalog

## Goal
Implement dynamic tool exposure from the MCP catalog according to `.pi/plans/mcp-client-implementation-plan.md`.

Goal: discovered MCP tools are visible to the LLM through the existing ToolRegistry/toolbox path before LLM schema resolution.

Scope:
- Read the session MCP catalog from every process that needs tool definitions; do not assume dynamic ToolRegistry state is shared across processes.
- Implement tool name mapping/sanitization using `{server}_{tool}` by default.
- Register MCP tools as dynamic tools with existing `ToolRegistry::addDynamicTool()` or equivalent.
- Preserve mapping from Hatfield tool name back to MCP server/tool name.
- Default MCP tools to sequential execution mode in v1.
- Detect collisions with permanent tools and other MCP tools; diagnose rather than overwrite.
- Ensure registration happens before LLM-visible tool schema resolution.

Depends on: MCP-03.

## Acceptance criteria
- MCP tools from the catalog appear in the active tool set before LLM calls.
- LLM-visible MCP tool names are namespaced/sanitized and schemas come from MCP input schemas.
- Name collisions are handled with clear diagnostics and no silent overwrite.
- Dynamic tool registration works correctly across the multi-process runtime model by using durable catalog data.
- MCP tools default to sequential execution mode.
- Castor validation covers tool registration/schema visibility.

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
- Created: 2026-06-12T18:06:42.104Z
