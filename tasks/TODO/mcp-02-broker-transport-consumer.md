# MCP-02 Broker transport and single MCP consumer

## Goal
Implement phase 1 broker transport foundation from `.pi/plans/mcp-client-implementation-plan.md`.

Goal: add a dedicated `mcp` Messenger transport/consumer supervised by the controller, but without full tool invocation yet.

Scope:
- Add a Messenger transport/queue for MCP broker messages.
- Update controller/consumer supervision so exactly one `mcp` consumer is launched per controller/session in v1.
- Add broker lifecycle/message skeletons such as initialize, refresh catalog, call tool, and disconnect commands as needed.
- Add structured logging fields for MCP broker lifecycle (`component=mcp`, `run_id`, `session_id`, `server_name`, `transport`, `correlation_id` where applicable).
- Ensure this remains CodingAgent app-layer infrastructure, not AgentCore/TUI/ExtensionApi.

Depends on: MCP-01.

## Acceptance criteria
- Controller/supervisor starts one MCP consumer alongside existing run_control/llm/tool/scheduler consumers.
- The MCP consumer can receive and handle a no-op or initialization message without affecting normal sessions.
- V1 configuration prevents accidental multiple generic MCP consumers.
- Structured MCP broker lifecycle logs are present and redact sensitive fields.
- Castor validation for touched runtime/Messenger code is run as required by project instructions.

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
- Created: 2026-06-12T18:06:24.722Z
