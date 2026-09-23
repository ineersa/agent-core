# OM hybrid retrieval benchmark

Measured on 2026-09-22 with PHP 8.5.10, Vektor 2.0.2, and Symfony AI Store 0.12.0.

## Revisions and method

The full backfill used production implementation `b4966eff9` and the harness committed as `7da27b77c`. That already-running process was neither restarted nor signalled during review fixes. The final query-only run used `ceeeca55ac2d0df386ac30ec357254684095355c` against its completed index. Indexing and stored-vector formats did not change between those revisions.

The harness opens the integration OM database read-only and creates a consistent SQLite backup. Every subsequent write targets the private copy. It invokes production `SemanticIndexService`, constructing a fresh service for each bounded indexing batch. Timings include source reconciliation, chunking, HTTP embedding, Vektor insertion, and SQLite writes. They exclude Messenger delivery, worker startup, and per-job connection creation. Query timings include production retrieval and optional reranking, but not tool-result serialization.

The local endpoints were:

- Embeddings: `http://localhost:8059/v1`, `coderankembed-q8_0.gguf`, 768 dimensions.
- Reranking: `http://localhost:8060/v1`, `bge-reranker-base-q8_0.gguf`.

Document chunks use 1,200 bytes, 192-byte overlap, and at most 80 lines, preserving UTF-8 boundaries. Embedding batches contain at most four inputs. Query embeddings prepend `Represent this query for searching relevant code:` and one space. The reranker receives the raw query, at most eight documents per call, and at most 768 characters per document.

These are single-run observations, not latency percentiles or a general relevance evaluation. Vektor graph construction is randomized. Endpoints may have warm model caches.

## Reproduction

Run from the task checkout:

```bash
castor om:benchmark-semantic \
  /home/ineersa/projects/agent-core/.hatfield/extensions-data/observational-memory/om.sqlite \
  var/reports/om-semantic-full-corpus.json
```

The command prints its private artifact directory. Reuse only that completed copy to measure changed query code without rebuilding or re-embedding:

```bash
castor om:benchmark-semantic --reuse \
  var/tmp/om-semantic-benchmark-5eb55cac6288c6e3/om.sqlite \
  var/reports/om-semantic-reviewed-queries.json
```

Choose new report filenames for subsequent runs. `--reuse` rejects databases outside the checkout's private benchmark directories and skips indexing entirely. The full-build task has a 55-minute safety budget and preserves private checkpoint artifacts if interrupted.

Reports contain counts, timings, memory IDs, and source-session IDs, not memory contents or HTTP bodies. The private directory also contains the copied database and derived text metadata. Delete it after investigation when it is no longer needed.

## Full backfill cost

| Measurement | Result |
|---|---:|
| Observations | 9,254 |
| Reflections | 98 |
| Source memories | 9,352 |
| Indexed chunks | 9,364 |
| Embedding requests / bounded batches | 2,341 |
| Backfill wall time | 1,945.162 s, or 32m25s |
| Longest synchronization call | 2.262 s |
| Unchanged-index synchronization | 95.541 ms |
| Additional embedding requests for unchanged index | 0 |
| Full benchmark process peak PHP memory | 143.676 MiB |
| Final query-only process peak PHP memory | 129.672 MiB |

The no-work check compares HTTP request counters before and after synchronization and fails if any request occurs. The final query-only run recorded zero build requests as well.

Each four-chunk batch currently reloads and rechunks the source snapshot, then rechecks source freshness. This makes source traversal quadratic across a full build at a fixed batch size. The measured maximum synchronization call remained below three seconds for this corpus, but the 32-minute initial build is a real cost. These measurements do not justify assuming linear scaling to much larger corpora. A resumable source cursor is future optimization work, not implemented or hidden by this report.

Peak memory includes Castor and application bootstrap, source snapshots, and vendor structures. It is not isolated vector-store memory, so it cannot fairly be compared directly with earlier synthetic PHP-array scans.

### Storage after checkpoint

| File | Bytes |
|---|---:|
| Copied `om.sqlite`, including derived FTS/text data | 22,380,544 |
| `vector.bin` | 29,112,676 |
| `graph.bin` | 3,033,941 |
| `meta.bin` | 561,840 |
| `payload.bin`, including chunk text metadata | 3,290,321 |
| All four Vektor files | 35,998,778 |

The SQLite WAL was empty after checkpoint. These are final sizes, not an assertion that every byte is incremental index overhead.

## Retrieval quality and latency on the reviewed revision

The target is the original upstream work in session `32`. For a reproducible reference set, the harness selects the 135 observations in that session containing `2510` or `MapToolArguments`. This is a lexical proxy for known target memories, not an exhaustive human relevance label set. Other observations and reflections in session `32` can also be relevant.

The table reports the first source-session `32` result among the default 20 results. Parent-memory IDs are preserved for `recall`.

| Query | Hybrid latency | Hybrid first session 32 | With reranker latency | With reranker first session 32 |
|---|---:|---:|---:|---:|
| `MapToolArguments` | 177.646 ms | 1 | 401.702 ms | 1 |
| `2510` | 247.958 ms | 3 | 489.193 ms | 1 |
| `where we upstreamed flat DTO arguments` | 170.926 ms | 1 | 439.836 ms | 1 |
| `Symfony AI tool arguments flattened from a data transfer object instead of nested JSON` | 179.671 ms | 2 | 459.012 ms | 3 |

The first labeled observation ranks were respectively `1, 3, 1, 3` without reranking and `1, 2, 2, 3` with reranking. The paraphrase locates the original source session first without requiring a PR number or symbol. Reranking improves the PR-number query but is not uniformly better: it moves the first source-session hit down for the concept query.

Each unfiltered query made one embedding request. Configured reranking made 13 requests for the bounded 100-chunk candidate set. All these responses were marked truncated.

### Negative query

`quasar glacier pineapple zyxwv-no-such-memory-71a9` returned 20 nearest candidates in both modes, taking 245.279 ms without reranking and 511.293 ms with reranking. These are not evidence that the requested topic exists. This benchmark ran without a relevance floor. The subsequent [score calibration](om-relevance-calibration.md) added an optional model-specific floor, but it is not an absence detector. Callers must inspect results and verify provenance.

### Bounded date filtering

The final revision caps each dated store request at 500 candidates, filters dates, and keeps at most 100 surviving candidates per store. A deterministic adapter test asserts `k=500` even for a declared million-chunk corpus. The live harness also verifies every returned timestamp against the requested bounds.

| Query and bounds | Hybrid | With reranker | Results | Truncated |
|---|---:|---:|---:|---|
| `MapToolArguments`, 2026-09-19 only | 526.735 ms | 549.830 ms | 8 | true |
| `MapToolArguments`, after 2999-01-01 | 519.403 ms | 551.297 ms | 0 | true |

The first case used one reranker call for eight survivors. The second made no reranker call because no candidates survived. Both preserve date correctness and expose the exhausted candidate budget, including the zero-result case. Neither claims exhaustive date-range recall beyond that budget.

## Validation of review fixes

Deterministic tests prove bounded candidate retrieval and truthful truncation, structured memory-unit indexing progress, preservation of healthy embeddings after a simulated SQLite busy error, and snapshot revalidation when indexing occurs during query embedding. Another test permits synchronization inside the reranker callback, proving that HTTP reranking does not retain the writer lock. Only explicit corruption or missing storage invalidates a healthy index; failures remain visible without fallback results.

The full build, final query-only run, and deterministic tests all completed. No second full index was built to produce the reviewed-query measurements.
