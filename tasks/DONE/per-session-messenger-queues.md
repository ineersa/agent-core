# Per-session messenger queues

## Goal
## Problem

Current shared queues (`run_control`, `llm`, `tool`) have a correctness risk during controller restarts:
- While controller is down, other sessions' consumers can drain shared queues
- On restart, the user won't see events that were consumed by other sessions
- Stale consumer cleanup uses `pgrep -f messenger:consume` which kills ALL consumers, not session-specific ones

## Solution

Implement per-session Doctrine Messenger queue names. Each session gets its own set of queues:
- `run_control_{sessionId}` instead of `run_control`
- `llm_{sessionId}` instead of `llm`  
- `tool_{sessionId}` instead of `tool`

All within the same SQLite `messenger_messages` table (queue_name is just a column filter).

## Design

### DSN construction
`JsonlProcessAgentSessionClient::spawnProcess()` already sets `HATFIELD_*_TRANSPORT_DSN` env vars with `doctrine://default?queue_name=run_control`. Change to `doctrine://default?queue_name=run_control_{sessionId}` where sessionId comes from the run.

### Consumer launch
`ConsumerSupervisor::launch($transportName)` builds `messenger:consume $transportName`. The transport name must now resolve to the session-scoped queue. Since the DSN is set via env vars per-process, the controller process already has the right queue_name baked in. The Symfony `messenger:consume` command uses the transport name from `framework.messenger.transports` config — which reads the env var.

**Key insight**: The transport names in `messenger.yaml` (`run_control`, `llm`, `tool`) are Symfony service IDs. The `queue_name` is embedded in the DSN env var. So we DON'T change transport names — we change the queue_name in the DSN. The consumers still do `messenger:consume run_control` but the underlying queue_name is session-scoped.

Wait — that means ALL consumers on the same controller share the same session-scoped queue, which is exactly what we want (one session per controller process).

### Orphan cleanup
`killOrphanedConsumers()` currently uses `pgrep -f messenger:consume`. Change to `pgrep -f "messenger:consume.*{sessionId}"` or check `/proc/{pid}/cmdline` for the session-specific transport DSN. This allows multiple Hatfield instances to run safely (each with their own session queues).

Actually, since transport names don't change (still `run_control`, `llm`, `tool` in the process args), we need another approach. Options:
1. Pass `--runs-uuid` or similar marker to messenger:consume for identification
2. Use the ppid=1 check (current approach) which is already multi-instance safe
3. Check the DSN env var in `/proc/{pid}/environ`

Best approach: pass a `HATFIELD_SESSION_ID` env var to consumer processes, then check `/proc/{pid}/environ` for the session ID during cleanup.

### Trade-offs
- ✅ Per-session isolation — no cross-session message stealing
- ✅ Targeted stale consumer cleanup by session ID
- ✅ Same SQLite DB (queue_name is just a column value)
- ❌ One session cannot be opened in 2 Hatfield instances simultaneously
- ❌ Slight overhead from more queue_name values in messenger_messages table

### Messenger YAML changes
The `messenger.yaml` transport DSNs stay the same (they read from env vars). The env vars are set per-process in `spawnProcess()`.

### Components to change
1. `JsonlProcessAgentSessionClient::spawnProcess()` — set DSN env vars with session-scoped queue_name
2. `ConsumerSupervisor::launch()` — accept sessionId, pass `HATFIELD_SESSION_ID` env var to consumer
3. `HeadlessController` — pass sessionId to ConsumerSupervisor, use in orphan cleanup
4. `killOrphanedConsumers()` — filter by session ID instead of killing all orphaned consumers
5. `.env` — no change needed (only used as default, overridden by spawnProcess)

## Acceptance criteria
- Per-session queue names: run_control_{sessionId}, llm_{sessionId}, tool_{sessionId}
- ConsumerSupervisor passes HATFIELD_SESSION_ID env var to child processes
- killOrphanedConsumers filters by session ID, not all messenger:consume processes
- JsonlProcessAgentSessionClient spawnProcess sets session-scoped DSN env vars
- Controller launches consumers with session-scoped queues
- Existing tests pass, cs-fix clean, phpstan clean

## Workflow metadata
Status: DONE
Branch: task/per-session-messenger-queues
Worktree: /home/ineersa/projects/agent-core-worktrees/per-session-messenger-queues
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/45
PR Status: merged
Started: 2026-05-23T19:43:44.422Z
Completed: 2026-05-23T21:40:37.861Z

## Work log
- Created: 2026-05-23T19:43:28.868Z

## Task workflow update - 2026-05-23T19:43:44.423Z
- Moved TODO → IN-PROGRESS.
- Created branch task/per-session-messenger-queues.
- Created worktree /home/ineersa/projects/agent-core-worktrees/per-session-messenger-queues.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/per-session-messenger-queues.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/per-session-messenger-queues.

## Task workflow update - 2026-05-23T19:50:28.391Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/per-session-messenger-queues to origin.
- branch 'task/per-session-messenger-queues' set up to track 'origin/task/per-session-messenger-queues'.
- Created PR: https://github.com/ineersa/agent-core/pull/45

## Task workflow update - 2026-05-23T21:40:37.861Z
- Moved CODE-REVIEW → DONE.
- Merged task/per-session-messenger-queues into integration checkout.
- Merge made by the 'ort' strategy.
 docs/async-runtime-architecture.md                 | 70 +++++++++++++++-------
 .../Runtime/Controller/HeadlessController.php      | 47 ++++++---------
 .../Process/JsonlProcessAgentSessionClient.php     | 34 ++++++++---
 .../Runtime/Controller/E2E/ControllerSmokeTest.php | 29 ++++++---
 tests/Tui/E2E/TuiAgentSmokeTest.php                |  4 +-
 5 files changed, 116 insertions(+), 68 deletions(-)
- Removed worktree /home/ineersa/projects/agent-core-worktrees/per-session-messenger-queues.
- Pulled integration checkout: Merge made by the 'ort' strategy..
