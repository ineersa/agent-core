# Temporary artifacts

`castor check` and the standalone test tasks (`test`, `test:tui`, `test:tui-update`, `test:controller-replay`, `llm:cassette:record`) allocate one QA run ID of the form `qa-<date>-<time>-<pid>-<hex>` and export it as `HATFIELD_QA_RUN_ID`. Standalone tasks keep caller-provided report, temporary and cache overrides.

| Scope | Location |
|---|---|
| Reports, logs, coverage, diagnostics | `var/reports/<run-id>/` |
| Project fixtures and isolated HOME | `var/tmp/<run-id>/` (HOME at `qa-home/`; `TestDirectoryIsolation` fixtures beside it) |
| Symfony cache | `.hatfield/cache-<run-id>/` |
| OS temporary files | `<run-id>-*.d` beneath the system temporary base, outside the checkout; recorded in the report's `.os-temp` and exported as `TMPDIR` to test subprocesses |

When the run ends, Castor checks that no processes or tmux sessions tagged with the run ID remain, then writes a `.completed` marker to the report directory. A leak check failure or SIGKILL leaves the run unmarked; unmarked runs are never reclaimed automatically. Do not reuse a completed run ID.

Completion and `castor clean:cleanup` reclaim the exact temporary and cache scopes of every completed run and keep the latest 10 completed report directories regardless of result. Active, unmarked or malformed runs, explicit caller overrides, the system temporary base itself and unrelated files stay untouched. Build outputs, PHAR session copies, background-process artifacts and controller locks under `.hatfield/tmp/controller-locks/` have their own lifecycles and are not cleaned by this task.

ANSI snapshots and failure diagnostics saved by the tmux harness are written to `var/reports/<run-id>/diagnostics/`. Set `HATFIELD_KEEP_TMP=1` to copy both temporary scopes there before they are reclaimed.
