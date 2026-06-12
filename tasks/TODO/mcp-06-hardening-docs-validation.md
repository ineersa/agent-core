# MCP-06 Lifecycle hardening, documentation, and validation

## Goal
Finalize v1 MCP client support with lifecycle hardening, docs, cleanup, and full validation from `.pi/plans/mcp-client-implementation-plan.md`.

Goal: make the MCP implementation reliable and documented enough for code review/merge.

Scope:
- Ensure graceful session/controller shutdown disconnects MCP clients and closes STDIO transports/processes as far as SDK/runtime allows.
- Add stale result cleanup for request/reply records.
- Add reconnect-once or clear failure behavior for crashed MCP clients if safe.
- Add docs, likely `docs/mcp.md`, covering config paths/schema, STDIO/HTTP examples, bearer env vars, no OAuth v1, namespacing, serialization/parallelism limitation, and troubleshooting.
- Add or update tests for lifecycle/cleanup and documented examples.
- Run required Castor validation.

Depends on: MCP-05.

## Acceptance criteria
- Graceful shutdown disconnects broker-owned MCP clients and closes STDIO server processes as far as practical.
- Stale MCP result records are cleaned up.
- Documentation explains `.hatfield/mcp.json`, `~/.hatfield/mcp.json`, STDIO and HTTP examples, env interpolation, no OAuth, namespaced tool names, and v1 single-consumer serialization.
- Known SIGKILL/OOM orphan limitation is documented honestly.
- Full required Castor validation is run; because this touches runtime/LLM-visible flow, `LLM_MODE=true castor check` is required unless prerequisites are unavailable and blocker is recorded.
- No TUI behavior changes are introduced; if TUI behavior is added, a real TmuxHarness E2E proof is required.

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
- Created: 2026-06-12T18:07:02.440Z
