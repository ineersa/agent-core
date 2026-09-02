# Datadog setup for local Hatfield development

Optional local log/APM wiring for maintainers. Not model-visible.

## Principles

- Prefer structured event-style log messages with correlation fields (`run_id`, `session_id`, `component`, `event_type`).
- Do **not** log raw prompts, tool output, env secrets, API keys, or full session content by default.

## Castor helpers

```bash
castor datadog:smoke        # package/agent/ddtrace/log-path diagnostic
castor datadog:log-config   # print ops/datadog/hatfield.d/conf.yaml + install hints
castor datadog:smoke-log    # append one JSONL smoke line under project .hatfield/logs
```

## Agent log collection

1. Enable logs in the Datadog Agent (`logs_enabled: true`).
2. Install the sample config from this repo:

```bash
castor datadog:log-config
# then follow the printed install steps, e.g.:
sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d
sudo install -o dd-agent -g dd-agent -m 0644 \
  ops/datadog/hatfield.d/conf.yaml \
  /etc/datadog-agent/conf.d/hatfield.d/conf.yaml
```

3. Ensure the Agent user can traverse to and read Hatfield log files under the active
   log directory (`logging.path` / `HATFIELD_LOG_DIR`, default project `.hatfield/logs/`).
   On Linux this often means ACL execute on parent dirs and read on the log dir
   (`setfacl`); exact paths depend on your checkout layout — adapt the hints printed
   by `castor datadog:log-config`.
4. Restart the Agent and write a smoke line:

```bash
# Linux (systemd). Adapt for other service managers.
sudo systemctl restart datadog-agent
castor datadog:smoke-log
```

`castor datadog:smoke-log` currently writes under project `.hatfield/logs/` only. It does
**not** follow a custom `logging.path` / `HATFIELD_LOG_DIR`. For a non-default log path,
inspect or inject a smoke line in the configured directory manually.

Search Logs Explorer for the printed smoke message. The sample config includes
masking rules for common secret shapes.

## Hatfield process metrics

The Datadog Process Check can aggregate CPU, resident memory, and process I/O for
the Hatfield CLI, controller, and Messenger workers. Install the sample config:

```bash
sudo install -o dd-agent -g dd-agent -m 0644 \
  ops/datadog/process.d/conf.yaml \
  /etc/datadog-agent/conf.d/process.d/conf.yaml
sudo systemctl restart datadog-agent
```

The config matches Hatfield PHAR processes and source launches through
`bin/console`. It tags the resulting `system.processes.*` metrics with
`service:hatfield`, `env:dev`, and `app:hatfield`.

Check the integration after the Agent has completed one collection interval:

```bash
sudo datadog-agent check process
```

Use these dashboard queries:

```text
sum:system.processes.cpu.pct{service:hatfield AND $env}
sum:system.processes.mem.rss{service:hatfield AND $env}
per_second(sum:system.processes.ioread_bytes_count{service:hatfield AND $env})
per_second(sum:system.processes.iowrite_bytes_count{service:hatfield AND $env})
```

The Process Check must match every Hatfield process that belongs in the total.
Short-lived processes can disappear before collection. Datadog documents
`system.processes.cpu.pct` as inaccurate for processes that live for less than
30 seconds. Linux process I/O availability also depends on Agent access to
`/proc/<pid>/io`; verify both I/O metrics before adding dashboard widgets.

## APM (`HATFIELD_DATADOG` / ddtrace)

Castor launch helpers may wrap the agent process with Datadog APM env when auto-enabled:

| Condition | Behavior |
|---|---|
| `HATFIELD_DATADOG=0` (or false/off) | Force APM off |
| `HATFIELD_DATADOG=1` (or true/on) | Force APM on **only if** the `ddtrace` extension is loaded |
| unset | Auto: enable only when `ddtrace` is loaded **and** a local Agent trace endpoint is reachable (for example unix socket `/var/run/datadog/apm.socket`) |

When enabled, helpers set `DD_TRACE_ENABLED`, `DD_TRACE_CLI_ENABLED`, service/env/version,
log injection flags, and prefer the local APM socket when present. `DD_TRACE_ENABLED=0` in
the environment also keeps auto mode off.

Use `castor datadog:smoke` to inspect package status, `ddtrace` load, and today's log path.

## Related

- Logging keys: [settings.md](settings.md)
- Runtime privacy notes: root `AGENTS.md`
