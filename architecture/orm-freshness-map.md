# Database freshness and ownership

[Architecture](README.md) · [State and recovery](state-and-recovery.md)

A Doctrine identity-map hit can return old fields after another process writes the
same row. Re-running a query is not necessarily a refresh. Mutable cross-process
reads need an explicit freshness boundary; they do not justify clearing the entire
EntityManager before every query.

## Current boundaries

| Area | Read/write boundary |
|---|---|
| Session catalog | `HatfieldSessionRepository::findForCatalog()` uses scalar DBAL rows; existence uses a dedicated SQL query. |
| Deferred completion | `DeferredToolCompletionRepository` reads mutable entities through `findFreshOneBy()` with `Query::HINT_REFRESH`; conditional SQL completion detaches a matching managed entity. |
| Deferred batches and children | Repositories use targeted fresh entity reads, scalar candidate queries and version/status-checked writes. Reservation/projection operations retain their own transaction-completion clear points. |
| Background Bash | `ProcessStore` reads immutable scalar snapshots and performs conditioned DBAL writes. No managed process entity crosses a cooperative wait. |
| Queue broker | Its separate SQLite connection/helper owns transport rows. ORM refresh does not affect queue delivery. |
| Extension storage | Concrete packages own their storage. Observational-memory repositories use SQL values, not host ORM identity-map state. |

## Invariants

- Refresh mutable fields **before** assigning new values. Refreshing afterwards
  can erase the pending update.
- Check current identity/version/status when mutating. A fresh candidate snapshot
  is an observation, not authorization to signal a process or settle a lifecycle.
- Do not retain managed entities across suspended Fibers or outside their owner
  transaction. Pass immutable scalar snapshots to execution work.
- Fiber consumers disable per-message service reset. Clearing shared services
  after one handler would invalidate suspended siblings.
- Targeted refresh/detach does not replace SQL uniqueness, optimistic locking,
  session ownership or exact native-process evidence.
- Explicit transaction-completion clears in repositories are not permission to
  add blanket clears in nested helpers or execution handlers.

Example failure: a cached session says model A, another process writes B, then the
user selects A. Assigning A to an already-A cached entity can produce no update.
The operation must read current fields before deciding or assigning the selection.

Sources: [session repository](../src/CodingAgent/Entity/HatfieldSessionRepository.php),
[deferred completions](../src/CodingAgent/Entity/DeferredToolCompletionRepository.php),
[batches](../src/CodingAgent/Entity/DeferredSubagentBatchRepository.php),
[children](../src/CodingAgent/Entity/DeferredSubagentChildRepository.php),
[process store](../src/CodingAgent/Tool/BackgroundProcess/ProcessStore.php),
[Fiber consumer](../src/CodingAgent/Runtime/Messenger/LlmFiberConsumeCommand.php).
