---
name: testing
description: "Load before test work or runtime, TUI, Messenger, and database validation."
---

# Testing

- Run QA only through Castor in the exact worktree. Start with `castor test --filter=X` for focused unit/integration proof.
- Read `tests/AGENTS.md` before test work. Every agent and fork must follow this prerequisite.
- Prove behavior at the lowest correct layer. Use live LLM or tmux only for contracts unavailable below.
- Keep cases <=10 seconds under normal load and relevant contention.
- For rare exceptions, document in the test the unique external/process contract, why lower layers cannot prove it, and that the timeout is a safety cap.
- Delete or demote flaky, soft, duplicate, or timing-window tests. Never fix them with arbitrary sleeps, delayed fixtures, retries, or higher timeouts.
- Assert positive readiness through bounded predicates, events, or artifacts. Timeouts are safety caps, not synchronization.
- Never busy-spin readiness loops. Yield within bounded predicate polls or block on a real event.
- Contention and locking proofs need deterministic barriers (locks, pipes, or markers coupled to child liveness). Timing lotteries are unacceptable.
- Own and isolate every process/resource; tear down the owned tree deterministically. Never signal root-owned or `HATFIELD_SESSION_ID` processes.
- Boot the Symfony kernel and use its test container for database tests. No standalone ORM factories.
- Keep helpers in tests. Never add production APIs, settings, paths, or constructor bypasses solely for test access.
- Record the removed behavior and exact remaining proof for test deletions/demotions; justify or get approval for uncovered requirements.
- Do not claim mocked mechanisms were exercised live.

## Load only the relevant reference

- Writing fixtures, doubles, or database tests: [helpers](references/helpers.md).
- Controller/TUI process work: [E2E](references/e2e.md).
- Provider or LLM-visible changes: [live LLM](references/live-llm.md).
- Unfamiliar QA commands or full-gate prerequisites: [commands](references/commands.md).
- Failures, hangs, or parallel flakes: [diagnostics](references/diagnostics.md).
- Auditing test value, consolidating coverage, or deleting/demoting a test: [test value](references/value.md).

## Required gate

Run `castor check` for TUI runtime, `AgentSessionClient`, Messenger, `TranscriptProjector`, `RuntimeEventPoller`, model routing, or LLM-visible flow changes.
For tracked tasks, run focused checks during implementation; the CODE-REVIEW transition owns the full gate. Post-merge validation checks integration separately.
If required tmux or live-model infrastructure is unavailable, stay IN-PROGRESS and report the exact blocker.
