---
name: datadog
description: "Use for Datadog investigation or explicitly authorized resource changes."
disable-model-invocation: true
---

# Datadog

- Start read-only. Require explicit approval of the target and effect before each resource mutation.
- Use the configured MCP connection; never expose credentials or unrelated tenant data.
- Use runtime tool names, not guessed names. Never call internal `_dd_*` tools marked `do-not-call`.
- Start with a narrow time range, service, environment, fields, and limit. Expand only when evidence requires it.
- Before Hatfield queries, read [telemetry](references/hatfield-telemetry.md).
- Before dashboard work, read [dashboard guidance](references/hatfield-dashboard.md).
- Before unfamiliar MCP calls, read [MCP guidance](references/mcp.md). For another service, discover its Datadog guides first.
- Use aggregations for counts and trends. Correlate timestamps, service, environment, trace IDs, and changes.
- Separate observation from inference. Report missing evidence rather than guessing.
- Include the question, query or identifier, time range, source tool, limits, evidence, correlation, and uncertainty in handoffs. Do not paste raw log bodies.
