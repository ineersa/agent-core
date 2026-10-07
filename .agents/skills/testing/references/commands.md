# Castor QA commands

Run commands from the exact worktree. Use `castor list` for unlisted tasks and options.

| Command | Use |
|---|---|
| `castor test --filter=X` | Focused unit or integration tests, sequential |
| `castor test --suite=X` | Selected suite, ParaTest |
| `castor test` | Unit and integration, ParaTest; excludes controller replay, TUI replay, and live LLM |
| `castor test:controller-replay` | Controller JSONL/process contracts with fixtures |
| `castor test:tui --filter=X` | Focused replay-backed terminal proof |
| `castor test:tui` | Full terminal replay group, ParaTest |
| `castor test:llm-real --filter=X` | Focused provider proof on the test model |
| `castor test:controller` | Opt-in live controller smoke; fixed filter |
| `castor phpstan --path=PATH` | Scoped static analysis |
| `castor lsp:check --path=PATH` | Scoped Symfony runtime diagnostics |
| `castor cs-fix --path=PATH` | Scoped formatting |
| `castor deptrac` | Architecture boundaries |
| `castor cs-check` | Formatting check |
| `castor dead-code` | Unsupported callers and dead paths |
| `castor docs:validate` | Built-in docs catalog and package-safe links |
| `castor phar:ensure` | Build missing or stale PHAR |
| `castor test:tui-update` | Refresh snapshots; no filter or PHAR ensure |
| `castor llm:fixtures:info` | Inspect committed replay fixtures |

## Full gate

`castor check` runs architecture, unit/integration, controller replay, TUI replay, live LLM, static analysis, runtime diagnostics, dead-code, style, and docs lanes. It requires tmux and llama.cpp or llama-proxy on port 9052. Symfony CLI Language Tools must be >=0.21.0; missing tools, errors, or incomplete analysis fail. Warnings remain visible.

The absolute gate budget is 210 seconds from entry, including lock wait, setup, lanes, and finalizers. Test runners also have a 210-second cap with owned-tree reaping. Never increase these budgets to hide failures.

Use the normal shared-worktree lock and llama-proxy cache guard. Disabling either is investigation-only, not review evidence. Default check workers are unit=4, TUI=2, live=1. Do not tune concurrency to hide contention.

The gate creates `var/reports/qa-<id>/`; use the directory printed by Castor, not a presumed `HATFIELD_QA_REPORTS_DIR`. Focused lanes accept a unique reports directory. Inspect lane logs and JUnit case durations.

Castor clears active-session transport DSNs for QA. Do not replace that with manual production transport setup. Strict PHPUnit lanes fail on all issues. `castor check` asserts worker ownership but does not auto-kill leaked workers.
