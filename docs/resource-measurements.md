# Resource measurements

Exploratory observations for SPEC-07. The controlled packaged resource baseline
is incomplete. These runs do not establish a memory plateau. Not model-visible.

Measurement artifacts for SPEC-07 live outside the repository under
`/home/ineersa/projects/agent-core-tasks/reports/spec07-resource-baseline/`.

Error artifacts omit unreviewed exception text and terminal captures.
`artifact-sanitization.json` records the original file hashes. Scalar measurements
are unchanged.

## Recorded commands and conditions

The worktree PHAR was built with `castor phar:ensure`. Exploratory runs used
`castor --castor-file=var/tmp/spec07-measure.castor.php measure:packaged-resume`
and ignored checkpoint-harvester helpers. The harness did not establish reliable
readiness or process-tree attribution. These commands are an execution record,
not an approved reproduction procedure. Repair and preserve the harness before
repeating the experiment.

Only frozen copies of inactive archives may be used as runtime input. Never
inspect an original SQLite index with a create-capable connection. Keep current
process limits; this PHAR entrypoint uses `memory_limit=512M`. Close owned tmux
panes cooperatively with Ctrl+D. Never signal root-owned or
`HATFIELD_SESSION_ID` processes.

## Exploratory runs at `766e3f6fd`

| Item | Value |
|---|---|
| PHAR sha256 | `0f960e494b0c54e398e340e3960042a8efa207724cd3e92d3e1dc3cd8d3159dd` |
| Source archive | inactive copy of `sessions/59/events.jsonl` |
| Source sha256 | `3e1fef9dab1244d3bb615e2240e7383def7dd0e9b630b716e158677df8d98c46` |
| Source size | 23,066,618 bytes |
| Page cache | warm (files hashed/copied earlier; no `drop_caches`) |
| Alternate candidate | sqlite-queue session 2 = 65,054,945 bytes (not copied for this baseline) |

### Packaged resume attempts from archived prefixes

The table records PHP allocator lifetime peaks from isolated logs, not live RSS
or settled heap. `tui.resume.mounted` is emitted while mounting the restoring
notice, before the owner's sealed transcript is installed. Its flat value does
not measure archive reconstruction or a completed resume. Shutdown samples have
different retained transcript sizes, and some attempts did not pass readiness.

Recorded archive sizes can include runtime appends to the copies. In particular,
the full archive started at 23,066,618 bytes but was reported as 24,099,879 bytes
after the attempts. These are not equal-active-window comparisons.

| Prefix | Recorded archive bytes | Restoring-notice peak MiB | Shutdown peak MiB | Shutdown transcript blocks | Recorded index bytes | Owner registry states |
|---|---:|---:|---:|---:|---:|---:|
| c01 | 2,371,010–2,387,095 | 51.79 | 53.79–63.79 | 1–434 | 0–122,880 | 1 |
| c03 | 5,227,859 | 51.79 | 63.79 | 274 | 188,416 | 1 |
| c06 | 11,243,379 | 51.79 | 63.79 | 422 | 413,696 | 1 |
| full | 24,099,879 | 51.79 | 69.79 | 795 | 790,528 | 1 |

Observations:

- The restoring-notice peak stayed at 51.79 MiB. That is a startup-screen sample,
  not evidence that memory is independent of archive size.
- Shutdown peaks ranged from 53.79 to 69.79 MiB at different transcript sizes.
  The experiment cannot attribute the difference to archived or retained data.
- The owner reported one resident parent state after attach. No completed-child
  accumulation workload was driven, so this does not prove child-state release.
- Index files were rebuilt in the copies. Live role-specific Agent metrics were
  not collected; the new `process.d` configuration was not deployed.

### Source-test owner bootstrap plateau (not packaged PHAR)

`SessionBootstrapProducerTest::testOwnerAndSpoolMemoryPlateausInSeparate128MProcesses`:

| Turns | Archive bytes | Peak bytes |
|---:|---:|---:|
| 256 | 1,216,632 | 73,924,608 |
| 4096 | 19,497,176 | 73,924,608 |

Separate cold 128M processes. Peak identical across ~16× archive growth. Label as
source-test evidence, not packaged PHAR proof.

## Remaining measurements

- Automated pane readiness waits timed out when scrollback mixed historical
  transcript text with chrome. Checkpoint presence alone does not prove readiness.
- Live RSS role-tree sampling by `HATFIELD_CWD` did not reliably see worker
  children. Sample the owned process tree and verify its identities before
  reporting per-role CPU, RSS, or I/O. Log heap samples are not substitutes for RSS.
- `/agents-live` did not show archived completed children in this packaged resume
  path, so child-view open/close cleanup was not measured.
- Warm same-process compaction or completed-child accumulation was not driven.
  Neither cold source-test results nor restoring-notice samples prove warm release.
- Process I/O figures from `/proc/<pid>/io` were not retained for the full matrix.
  Archive file size is not bytes physically read. Logical read counters and
  physical block I/O still need separate measurements.
- Host Datadog role matching requires deploying `ops/datadog/process.d/conf.yaml`
  and restarting the Agent. This measurement does not claim that deployment.

## Inspection side effect

An exploratory inspection accidentally created an empty `history-index.sqlite`
in the original inactive session 59 directory. The file was removed after its
provenance was established. The source `events.jsonl` hash remained unchanged.
Future inspections must operate on copies and open SQLite files read-only.
Main's post-handoff process inspection found no remaining measurement processes
under this worktree's `var/tmp/test-*` directories.

## Related telemetry

See [Datadog setup](datadog.md) for `process_role`, memory sampler fields, and
Process Check role markers.
