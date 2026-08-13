# Datadog setup for local Hatfield development

Optional local log/APM wiring for maintainers. Not model-visible.

## Principles

- Prefer structured event-style log messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`).
- Do **not** log raw prompts, tool output, env secrets, API keys, or full session content by default.

## Castor helpers

```bash
castor diag:datadog:smoke
castor diag:datadog:log-config
castor diag:datadog:smoke-log
```

Configure the Datadog Agent to tail Hatfield log files from the active log directory
(`logging.path` / `HATFIELD_LOG_DIR`). Exact Agent YAML is environment-specific.

## Related

- Logging keys: [settings.md](settings.md)
- Runtime privacy notes: root `AGENTS.md`
