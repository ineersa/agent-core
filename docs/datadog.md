# Datadog setup for local Hatfield development

Hatfield Datadog support is intentionally opt-in. The PHP `ddtrace` extension and the Datadog Agent are separate pieces:

- **APM/traces**: `ddtrace` in the PHP CLI process sends spans to the local Agent trace socket.
- **Logs**: the Datadog Agent tails Hatfield JSONL log files from `.hatfield/logs`.

## Current local paths

The checked-in Datadog log template tails:

- main checkout: `/home/ineersa/projects/agent-core/.hatfield/logs/*.log`
- task worktrees: `/home/ineersa/projects/agent-core-worktrees/*/.hatfield/logs/*.log`

## Agent log collection

1. Ensure `/etc/datadog-agent/datadog.yaml` contains only explicit collection settings for this use case:

   ```yaml
   logs_enabled: true
   container_collect_all: false

   apm_config:
     enabled: true
   ```

   Avoid `extra_config_providers: [process_log]` and broad process log collection when you only want Hatfield logs.

2. Install the Hatfield log config under an integration directory (not directly as `conf.d/conf.yaml`):

   ```bash
   sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d
   sudo install -o dd-agent -g dd-agent -m 0644 \
     ops/datadog/hatfield.d/conf.yaml \
     /etc/datadog-agent/conf.d/hatfield.d/conf.yaml
   sudo rm -f /etc/datadog-agent/conf.d/conf.yaml
   ```

3. Let the `dd-agent` user traverse the home directory and read Hatfield logs:

   ```bash
   setfacl -m u:dd-agent:--x /home/ineersa
   setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core/.hatfield/logs
   setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core-worktrees 2>/dev/null || true
   ```

4. Restart the Agent and generate a fresh log line:

   ```bash
   sudo systemctl restart datadog-agent
   castor datadog:smoke-log
   ```

The Agent tails from the current file offset; it will not backfill old Hatfield log lines after a new config is installed.

## Optional APM/traces

The normal launcher auto-enables Datadog APM when `ddtrace` is loaded and a local Datadog trace endpoint is reachable:

```bash
castor run:agent
```

You can force the default launcher either way:

```bash
HATFIELD_DATADOG=1 castor run:agent   # force-enable if ddtrace is loaded
HATFIELD_DATADOG=0 castor run:agent   # force-disable
```

Use the explicit Datadog launcher when you always want traces for an agent run:

```bash
castor run:agent-datadog
```

When enabled, the launcher sets:

- `DD_TRACE_ENABLED=1`
- `DD_TRACE_CLI_ENABLED=1`
- `DD_SERVICE=hatfield`
- `DD_ENV=dev` unless already set
- `DD_VERSION=<git short sha>` when available
- `DD_LOGS_INJECTION=true`
- `DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=true`
- `DD_TRACE_AGENT_URL=unix:///var/run/datadog/apm.socket` when the socket exists

## Local checks

```bash
castor datadog:status
php --ri ddtrace | grep -E 'enabled_cli|agent_url|service|env|append_trace_ids'
```

If logs do not appear, check:

- `/etc/datadog-agent/conf.d/hatfield.d/conf.yaml` exists.
- `logs_enabled: true` is set.
- `/home/ineersa` has an ACL entry for `dd-agent` execute traversal.
- a new log line was written after the Agent restart.

## Privacy note

Hatfield logs can contain prompts, paths, tool output, and exception context. The Agent config masks common secret shapes, but it cannot reliably remove all sensitive prompt/session content. Keep this enabled only for local development or after reviewing/redacting what the application logs.
