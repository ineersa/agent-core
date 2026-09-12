# OM search LIKE benchmark

Date: 2026-09-12

Purpose: choose bounded SQL `LIKE` versus FTS5 for `om_search` without modifying the live OM database.

## Setup

```bash
SRC_DB=/home/ineersa/projects/agent-core/.hatfield/extensions-data/observational-memory/om.sqlite
TMP=$(mktemp -d /tmp/om-search-probe-XXXXXX)
cp "$SRC_DB" "$TMP/om.sqlite"
```

Representative copy size during measurement: about 4928 observations and 24 reflections.

## Representative LIKE timings

```bash
python3 - <<'PY'
import sqlite3, time, os
db=os.environ['TMP']+'/om.sqlite'
c=sqlite3.connect(db)
sql=("SELECT observation_id, run_id, timestamp, substr(content,1,80) "
     "FROM om_observation WHERE content LIKE ? ORDER BY timestamp DESC LIMIT 20")
for needle in ('%2510%','%MapToolArguments%'):
    plans=c.execute('EXPLAIN QUERY PLAN '+sql,(needle,)).fetchall()
    times=[]
    n=0
    for _ in range(5):
        t0=time.perf_counter()
        n=len(c.execute(sql,(needle,)).fetchall())
        times.append((time.perf_counter()-t0)*1000)
    print(needle, plans, 'ms_avg', sum(times)/len(times), 'ms_min', min(times), 'n', n)
PY
```

Observed:

- `%2510%`: `SCAN om_observation` plus temp B-tree order; about 1.0 ms average; 2 rows
- `%MapToolArguments%`: same plan; about 1.5 ms average; 20 rows

## Disposable 50k-row corpus

```bash
python3 - <<'PY'
import sqlite3, time, shutil, os
src=os.environ['TMP']+'/om.sqlite'
scaled=os.environ['TMP']+'/om-scaled.sqlite'
shutil.copy(src, scaled)
c=sqlite3.connect(scaled)
base=c.execute(
    'SELECT run_id, boundary_key, source_start_seq, source_end_seq, source_refs_json, content, '
    'content_hash, relevance, timestamp, token_count, observer_model, observer_schema_version, created_at '
    'FROM om_observation'
).fetchall()
need=50000-len(base)
batch=[]; i=0
while i<need:
    row=base[i%len(base)]
    oid=f'{i:064x}'
    content=row[5]+f' clone-{i}'
    batch.append((oid,)+row[:5]+(content, row[6], row[7], row[8], row[9], row[10], row[11], row[12]))
    i+=1
    if len(batch)>=2000:
        c.executemany(
            'INSERT INTO om_observation(observation_id, run_id, boundary_key, source_start_seq, source_end_seq, '
            'source_refs_json, content, content_hash, relevance, timestamp, token_count, observer_model, '
            'observer_schema_version, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            batch,
        )
        c.commit(); batch.clear()
if batch:
    c.executemany(
        'INSERT INTO om_observation(observation_id, run_id, boundary_key, source_start_seq, source_end_seq, '
        'source_refs_json, content, content_hash, relevance, timestamp, token_count, observer_model, '
        'observer_schema_version, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        batch,
    )
    c.commit()
sql='SELECT observation_id FROM om_observation WHERE content LIKE ? ORDER BY timestamp DESC LIMIT 20'
for needle in ('%2510%','%MapToolArguments%'):
    times=[]
    for _ in range(3):
        t0=time.perf_counter(); c.execute(sql,(needle,)).fetchall(); times.append((time.perf_counter()-t0)*1000)
    print(needle, 'ms_avg', sum(times)/len(times))
c.execute(
    "CREATE VIRTUAL TABLE om_observation_fts USING fts5("
    "content, observation_id UNINDEXED, run_id UNINDEXED, timestamp UNINDEXED, tokenize='unicode61')"
)
c.execute(
    'INSERT INTO om_observation_fts(observation_id, run_id, timestamp, content) '
    'SELECT observation_id, run_id, timestamp, content FROM om_observation'
)
c.commit()
for needle in ('2510','MapToolArguments'):
    times=[]
    fts='SELECT observation_id FROM om_observation_fts WHERE om_observation_fts MATCH ? ORDER BY rank LIMIT 20'
    for _ in range(3):
        t0=time.perf_counter(); c.execute(fts,(needle,)).fetchall(); times.append((time.perf_counter()-t0)*1000)
    print('FTS', needle, 'ms_avg', sum(times)/len(times))
PY
```

Observed:

- LIKE `%2510%` about 12.8 ms average
- LIKE `%MapToolArguments%` about 15.8 ms average
- FTS `2510` about 0.18 ms average
- FTS `MapToolArguments` about 0.35 ms average

## LIKE case semantics check

```bash
python3 - <<'PY'
import sqlite3
c=sqlite3.connect(':memory:')
c.execute('create table t(c text)')
c.execute("insert into t values ('MapToolArguments'), ('maptoolarguments')")
print(c.execute("select c from t where c like '%MapTool%'").fetchall())
print('pragma', c.execute('pragma case_sensitive_like').fetchone())
PY
```

Observed: ASCII `LIKE` is case-insensitive by default (`case_sensitive_like` unset).

## Decision

Keep bounded escaped `LIKE` without an FTS migration for this task.
