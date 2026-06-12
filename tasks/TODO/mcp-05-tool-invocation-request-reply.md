# MCP-05 Tool invocation through broker request/reply

## Goal
Implement MCP tool calls from normal tool workers through the single MCP broker, as designed in `.pi/plans/mcp-client-implementation-plan.md`.

Goal: when the LLM calls a dynamic MCP tool, the normal ToolExecutor invokes an MCP handler that sends a correlated request to the broker, waits for the result, maps it, and returns through the existing tool pipeline.

Scope:
- Implement broker request/reply messages and correlation IDs for MCP `callTool`.
- Implement a result store for correlated results, preferably DB-backed unless a locked session file store is deliberately chosen.
- Implement tool-worker-side `McpToolHandler` with synchronous wait/poll and timeout.
- Implement broker-side call handler using `McpConnectionManager::callTool()` / SDK `Client::callTool()`.
- Implement `McpResultMapper` from MCP content blocks/errors to normal Hatfield tool result payloads.
- Handle timeouts, broker errors, SDK errors, and stale results.
- Ensure existing ToolExecutor/FaultTolerantToolbox error handling remains the outer boundary.

Depends on: MCP-04.

## Acceptance criteria
- A normal tool worker can invoke a discovered MCP tool through the broker and receive a result.
- With multiple tool workers configured, STDIO MCP server/process ownership remains broker-only and is not duplicated per worker.
- MCP text results map to normal tool output; unknown structured content is represented safely.
- MCP errors and timeouts become normal failed tool results without crashing workers.
- Correlation result records are cleaned up or marked stale after timeout/completion.
- Castor tests cover successful call, error call, timeout, and multi-worker STDIO ownership as far as practical.

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
- Created: 2026-06-12T18:06:52.879Z
