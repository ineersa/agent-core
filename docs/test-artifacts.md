# Temporary artifacts

**Before deploying this build, stop every old source and PHAR controller.** Old flat locks and new managed directories do not exclude each other. Restart only updated builds. Never delete the permanent controller directory coordinator.

Castor gives fixtures and isolated HOME directories a `var/tmp/qa-*` scope, exports it as `TMPDIR`, and uses exact-run cache cleanup after the existing process and tmux shutdown checks. The shared test allocators cover MCP, Hatfield, TUI and task-workflow fixtures; SafeGuard sibling files stay inside that scope.
A `.completed` report marker records proved consumer shutdown before cleanup. Do not reuse a completed run ID. Keep the latest 10 completed runs regardless of result. `clean:cleanup` recovers their exact temporary and cache scopes; active, unmarked, malformed and external resources remain untouched. SIGKILL before that proof leaves an unknown run, not an eligible deletion.
ANSI snapshots and explicitly requested `HATFIELD_KEEP_TMP` diagnostics live under the bounded QA reports. Explicit caller overrides remain outside automatic ownership.
Reclaim the historical integration backlog only after the coordinated restart and source-verified classification. Record reclaimed and retained resources before DONE. Background-process management and its existing startup, shutdown and periodic cleanup are unchanged.
