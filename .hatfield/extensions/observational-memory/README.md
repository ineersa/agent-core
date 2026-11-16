# Observational Memory (OM) extension

Extension-owned observational memory storage, asynchronous Observer pipeline,
threshold Reflector + Dropper generations, and CompactRun replacement summaries via
instant durable-memory projection.

## Main runs only

OM never operates in named subagents or forks, even if explicitly enabled in their
extension allowlists. OM checks the public `isChildRun(runId)` capability before
observation dispatch, queued observation/reflection work, compaction projection,
queries and TUI status polling. Unknown identity fails
closed. These checks use persisted session identity and native-child operational
relationships, not transcript scans.
`memory_search` and `recall` are also registered as main-only tools, so children
cannot inherit them through a tool allowlist or invoke them through code mode.

Parent-side compaction used to prepare a fork still runs OM for the **parent**.
Its memory summary may therefore appear in the fork's inherited conversation;
this is existing parent context, not OM running in the child. No inherited
conversation content is scrubbed by this restriction.

## Architecture (OM-03 + OM-04 + OM-05)

Hatfield provides a **generic** async extension-agent job facility. OM uses the
existing single FIFO `extension_agent` transport/worker for Observer/Reflector/Dropper
model work. Compaction does **not** wait on that worker.

```text
AfterTurnCommit (any run_control/llm/tool worker)
  → ObserveBoundaryTerminalHook (hot batch only)
  → ExtensionApi::dispatchExtensionAgentJob(scalar payload)
  → Symfony Messenger transport `extension_agent`  (session-owned sqlite-queue broker)
  → dedicated Hatfield messenger:consume extension_agent worker
      → ExtensionLoaderSubscriber loads extensions
      → ExtensionAgentJobWorker resolves handler by stable ID
      → ObserveBoundaryJobHandler / ObserverPipeline
          → open/migrate om.sqlite (per-job path-local DBAL connection)
          → SessionEventReader::readRange (async path)
          → deterministic configured context-window chunk/part packer
          → $api->agent()->run(... record_observations, maxToolCalls=6 ...)
          → transactionally persist observations + coverage parts
          → optional threshold dispatch observational_memory.reflect_generation

Threshold (after all observe chunks durable, tokens > 40000)
  → ReflectGenerationJobHandler
      → claim generation by exact threshold-generation-v1 id
      → delta Reflector (new reflections only, maxToolCalls=16, shared model)
      → if >=1 new reflection AND active observation pool > observations_max_tokens:
            bounded Dropper (propose ids, server ranks+caps, maxToolCalls=16)
      → promote om_active_generation once: prior+new reflections, active-minus-drops

CompactRun (run_control, under run lock) — Pi-style instant projection
  → public OmBeforeCompactionHook (paired coverage watermark 1..lastSeq)
      → ActiveMemoryProjector (listActiveReflections + listActiveCandidateObservations
        → ActiveMemoryRenderer::render, 12-char display ids)
      → non-empty → replaceSummary(text)
      → empty → continue() so core keep-recent / LLM summary path may run
  → no extension_agent dispatch, no model call, no session-event read, no poll/timeout

Snapshot / fork parent (CompactionService::compactMessages, trigger=fork)
  → same public OmBeforeCompactionHook (coverage watermark null/null; parent run_id)
      → same ActiveMemoryProjector as CompactRun
      → non-empty → replaceSummary into inherited child messages; no compaction model
      → empty / OM not registered → continue → ordinary model snapshot compaction
      → hook failure → fail closed (snapshot hard-fail, no silent model fallback)
  → structural below-threshold snapshots still no-op before hooks (unchanged)
  → OM's own eligibility checks block work once executing under a child run_id
```

OM does **not** own a private Symfony Kernel, bin/console, Messenger bus, consumer
supervisor, or priority/multi-receiver queue.


### Live TUI status notices

While an Observer/Reflector/Dropper model stage is running, the async worker writes a
single ephemeral `om_current_activity` row (per session/run). The TUI polls that row
through the public `TuiExtensionContextInterface::onTick` bridge (self-throttled to
≥250ms) and sets status key `om-background` with Pi-style copy, e.g.
`Observational memory: reflector running (~2,500 tokens)`. The row is cleared in
handler `finally` (job-id guarded); crash leftovers older than 5 minutes are hidden.
Status writes never fail model work.

### Freshness tradeoff (accepted)

Compaction renders whatever durable memory is already present. Observer/Reflector/Dropper
work finishing later affects a **later** compaction. Canonical `events.jsonl`
remains the source of truth for recall and later Observer catch-up on turn
boundaries.

### FIFO / failures (async observe + threshold only)

- `extension_agent` uses Symfony Messenger native default `max_retries: 3` (4 attempts total) and **no** failure transport.
- Exhausted jobs emit sanitized transient runtime event `extension_agent.job_failed`
  (seq=0) and a TUI Error block when `payload.run_id` is present.

### Async transport requirement

`ExtensionAgentJobDispatcher` is **fail-closed for `sync://`**.

The controller exports the session's broker socket in the DSN. The queue name
is `extension_agent`, without a session suffix.

| Mode | `HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN` |
|---|---|
| Process controller (production async) | `sqlite-queue://extension_agent?endpoint=<URL-encoded broker socket>` |
| Unit tests | `in-memory://…` or a mock bus with a `sqlite-queue://` DSN |
| Default `.env` `sync://` | **refused** at dispatch |

## Activation

Activate OM by listing its class under `extensions.enabled` and configuring one
shared exact model; installing the package alone does not enable it:

```yaml
# project .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      storage:
        database: .hatfield/extensions-data/observational-memory/om.sqlite
      model: llama_cpp_test/test
      observer:
        context_window_ratio: 0.65
      reflector:
        reflect_after_observation_tokens: 40000
      pools:
        observations_max_tokens: 30000
```

Install package dependencies into the project extension Composer root:

```bash
cd .hatfield/extensions
composer install
# or after package changes:
composer update ineersa/hatfield-ext-observational-memory
```

## Ownership boundaries

- **OM SQLite** (`.hatfield/extensions-data/observational-memory/om.sqlite`) owns
  observations, coverage, reflections, and generations. Historical compaction
  request/result tables may still exist from older migrations and are inert.
- **Hatfield** owns canonical `events.jsonl`, model infrastructure, and generic
  `extension_agent` FIFO/worker supervision. Session tree UI/command (`/tree`) is
  **not** shipped. OM does **not** own a private consumer or failure transport.
- **Replacement summaries** are deterministic PHP projections of current durable
  active memory. Reflector/Dropper models never author final compact text.
- **Source refs** stay SQLite-only; compact summaries do not include footnotes.
  Model-facing compacted-memory IDs are lowercase first-12-char prefixes (same as `/om-view`);
  full SHA-256 identities remain in SQLite/generation links only.
- **Session-global MVP:** non-branch-aware. Conversation history navigation does not roll back the OM pool.
- **Delivery gap:** events and OM SQLite can diverge after worker loss; later turn
  boundaries advance Observer coverage asynchronously.

## Commands, search, and recall

- `/om-status` — durable OM memory/activity aggregates for the current session
  (Observer → delta Reflector → bounded Dropper pipeline; compaction is instant projection).
- `/om-view` — active reflections and candidate observations with 12-char display ids,
  timestamp/relevance, content, and human source event sequences.
- `memory_search` — permanent ambient tool. Without Librarian settings, find prior work across sessions by one contiguous
  literal substring in retained observational-memory content (all history by default). Optional
  `after` / `before` memory-date filters (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`). Multi-word queries
  match that exact phrase, not AND of separate words. Results are newest first and bounded
  (default 20, max 50); truncated replies omit older matches and do not provide a total or
  pagination. Observation hits include `importance` assigned at recording time, not a query
  match score. Searches memory content only, not raw transcript events.
  No hits does not prove a conversation never happened; memory can lag or omit details. Prefer
  a single identifier in local mode, then `recall` for provenance. With Librarian configured,
  the same tool calls project-scoped external hybrid search, accepts natural-language
  descriptions and paraphrases, and returns relevance-ranked results; inspect their content
  before treating them as evidence.
- `recall` — permanent ambient tool; recover provenance for one known memory id from
  compacted memory, `/om-view`, or `memory_search` (unique lowercase 12–64 hex prefix, or full
  64-char SHA-256). The only argument is `id`: observations and reflections are resolved
  across this project's retained memories, and the matched record supplies its evidence
  session. `session_id` is output provenance, never a recall argument. Ambiguous prefixes
  (including collisions across memory kinds) return full IDs and actual memory content,
  bounded to 50 candidates and response-size limits; choose the intended memory and retry
  with its full ID. Observation importance is recording-time metadata, not a query score.
  Verify current repo or PR state before acting on historical decisions.

### Search implementation notes

Without Librarian settings, `memory_search` uses escaped SQL `LIKE` substring matching with a small result limit. Under
default SQLite settings (and OM does not enable `case_sensitive_like`), ASCII letter case
is insensitive, so `MapTool` matches `maptool`. `%` and `_` in the query are treated as
literals via `ESCAPE`.

Date filters accept real calendar values only (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`).
`after` must not be later than `before`. Date-only bounds cover the whole day. An HH:MM
`before` bound includes the entire minute for reflection `created_at` values.

Benchmark evidence (read-only disposable copies; never modify the live OM database) is
recorded under `.hatfield/extensions/observational-memory/docs/om-search-like-benchmark.md`, including the exact commands used
on representative (~4.9k observations) and disposable 50k-row corpora. Leading-wildcard
`LIKE` stayed in the low-millisecond to ~15 ms range for identifier queries such as `2510`
and `MapToolArguments`, with `SCAN om_observation` plans. FTS5 was faster at 50k rows but
needs schema/index maintenance and weaker exact-substring fidelity for punctuation-heavy
identifiers. Local mode keeps bounded `LIKE` search; Hatfield has no vector or FTS indexing path.

### Optional Librarian retrieval

Configure `extensions.settings.observational_memory.librarian` with the **full HTTP MCP
endpoint** to delegate both retrieval tools to one shared Librarian service:

```yaml
librarian:
  url: http://127.0.0.1:8093/_mcp
  timeout_seconds: 30
```

Omit the block or set it to `null` for local literal search and project-wide local
recall, with no external connection. An empty/malformed block is an error, not local
mode. `timeout_seconds` defaults to 30 and accepts integers from 1 through 300.
URLs must be absolute HTTP(S) endpoints without credentials, query, or fragment.
This integration adds no authentication policy or settings; anonymous Librarian works
unchanged. Do not register duplicate ordinary MCP tools or start a Librarian per session.

Linked Git worktrees always use local OM, even if they inherit a Librarian setting.
Their tools advertise literal search, use the configured local database for search/recall,
and never connect to Librarian or call `ensure_project`. This is an explicit checkout
policy, not fallback after a remote failure; normal checkouts still surface remote failures.
Git's resolved worktree/common metadata identifies linked worktrees, including nested CWDs;
a `.git` file alone does not exclude submodules or separate-git-dir checkouts. If existing
Git metadata cannot be inspected, retrieval stays local rather than risking registration.
No worktree is redirected to the primary checkout. Observation/reflection remain enabled
for main runs in worktrees; child/fork OM remains disabled.

The official PHP MCP SDK client connects lazily in the tool worker, reuses its connection,
and sends only `X-Librarian-Project` for project selection. The canonical absolute project
root follows the configured OM `.hatfield` storage location (or CWD for a custom external
storage path), not Git's top-level directory. There is no session-selection header.
The extension requires the published PHP MCP SDK `^0.9` API, including independently
installed extensions. Hatfield's root-only Composer alias preserves its already-tested
SDK source revision without forcing unrelated dev-main namespace/API changes.

Each newly initialized connection calls `ensure_project` once with `{}` internally,
then `memory_search` or `recall`; registration is not an additional model-facing tool.
Registration success means only that the project exists, not that discovery or indexing
has finished. The client never polls, waits for readiness, or triggers synchronous indexing.
Search can report `index_unavailable`; ID-only recall uses discovered canonical sources
and does not require a ready index. Domain failures preserve structured error fields and
ambiguity candidates, without silent local fallback or successful-empty error masking.
Transport failures/cancellation reset the connection for the next invocation; worker
shutdown disconnects it. Requests use bounded SDK/HTTP deadlines and response limits,
with cooperative cancellation during HTTP progress, not preemptive interruption of all I/O.

Search returns freshness (`partial`, source/committed revision) and truncation information;
bounded nearest results can be unrelated, and an empty response does not prove absence.
Queries and relevant candidate memory text go to the configured trusted service, but
logs never include their contents. The wire limit is 8 MiB (including the duplicated
MCP JSON payload), and structured payloads are capped at 1 MiB.

Observation, reflection, coverage, and compaction remain local, even when registration,
retrieval, or the service fails. Hatfield no longer schedules semantic jobs or loads vector
indexes, embeddings, or rerankers. Existing derived tables/directories are left untouched;
canonical memories, source revisions, migrations, and session journals are preserved.
### Generation model

One top-level `observational_memory.model` is shared by Observer, Reflector, and Dropper.
Thinking levels are not configured; provider defaults apply. Observer uses
`maxToolCalls=6` to allow correction rounds while bounding accumulated context;
Reflector and Dropper retain the Pi-mapped `maxToolCalls=16` cap.
