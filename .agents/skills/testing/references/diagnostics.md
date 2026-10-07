# Failure and contention diagnostics

1. Inspect the failing lane log, JUnit cases, and timeout process snapshot from the exact worktree.
2. List every case exceeding 10 seconds, including under relevant contention. Rewrite, demote, or delete it.
3. Diagnose owned worker leaks with `castor clean:cleanup:workers:list`.
4. Record leaking PIDs, commands, and originating test or lane. Fix teardown at the source.
5. Use `castor clean:cleanup:workers` only as a last resort for recorded current-user orphans in this checkout. Never signal root-owned or `HATFIELD_SESSION_ID` processes.

Do not retry until green, increase timeouts, add delayed fixtures, or disable the gate lock/cache guard as a fix.

## Known parallel flakes

Validate from the same worktree with concurrent normal `castor check`, standalone `castor test:tui`, and standalone `castor test:llm-real`. Give each process a unique reports directory; the gate prints its generated directory. Do not edit while lanes run. Wait for all exits and inspect case durations. Solo green is insufficient.

For tracked implementation, keep focused contention checks in task-start; the CODE-REVIEW transition owns the full gate. Follow the active phase procedure before running transition validation.

- Shared SQLite or missing transport databases require isolation fixes, not waits.
- Readiness failures need child-liveness-aware predicates and diagnostics.
- Timing races need locks, pipes, or deterministic barriers, not delayed replay.
- Soft live assertions need a required protocol/tool contract or deletion.
- Missing LLM generation despite healthy endpoints requires service diagnosis. Leave unrelated or root-owned workers alone.
