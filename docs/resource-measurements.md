# Resource measurements

Reference notes for packaged-process memory and disk baselines. Not model-visible.

Sanitized reproducibility artifacts for SPEC-07 live outside the repository under
`/home/ineersa/projects/agent-core-tasks/reports/spec07-resource-baseline/`.

## How to reproduce

1. Build the worktree PHAR: `castor phar:ensure`.
2. Copy an inactive archive into `var/tmp/test-<uuid>/` only. Never read or modify
   active sessions (including main session 75) from tests or measurement scripts.
3. Run the ignored helper:
   `castor --castor-file=var/tmp/spec07-measure.castor.php measure:packaged-resume`
   or the checkpoint harvester scripts under `var/tmp/spec07-measure-*.php`.
4. Keep current process limits. The PHAR entrypoint uses `memory_limit=512M`.
5. Tear down owned tmux panes with Ctrl+D. Never signal `HATFIELD_SESSION_ID` or
   root-owned processes.

## Baseline measured at `766e3f6fd`

| Item | Value |
|---|---|
| PHAR sha256 | `0f960e494b0c54e398e340e3960042a8efa207724cd3e92d3e1dc3cd8d3159dd` |
| Source archive | inactive copy of `sessions/59/events.jsonl` |
| Source sha256 | `3e1fef9dab1244d3bb615e2240e7383def7dd0e9b630b716e158677df8d98c46` |
| Source size | 23,066,618 bytes |
| Page cache | warm (files hashed/copied earlier; no `drop_caches`) |
| Alternate candidate | sqlite-queue session 2 = 65,054,945 bytes (not copied for this baseline) |

### Packaged cold resume of frozen compaction prefixes

Values are PHP heap checkpoints from `process.memory.checkpoint` /
`owner.registry.retained` in isolated run logs. They are not Datadog Process Check
RSS series. Host Agent `process.d` role config from this branch was not deployed
during measurement.

| Prefix | Archive bytes | Screen `tui.resume.mounted` peak MiB | Screen `tui.session.shutdown` peak MiB | Shutdown transcript blocks | History index bytes | Owner registry states |
|---|---:|---:|---:|---:|---:|---:|
| c01 | 2,371,010–2,387,095 | 51.79 | 53.79–63.79 | 1–434 | 0–122,880 | 1 |
| c03 | 5,227,859 | 51.79 | 63.79 | 274 | 188,416 | 1 |
| c06 | 11,243,379 | 51.79 | 63.79 | 422 | 413,696 | 1 |
| full | 24,099,879 | 51.79 | 69.79 | 795 | 790,528 | 1 |

Observations:

- Cold resume mount peak for `screen` stayed flat at 51.79 MiB across a 10× archive
  size range.
- Shutdown peak and retained transcript block counts rose with the retained display
  window, not linearly with full archive bytes.
- `owner.registry.retained` reported `registry_state_count=1` after resume attach
  (parent only). Completed children were not kept as owner execution states.
- Index files were rebuilt in the isolated copies. Original session 59 had no
  durable index in this measurement window.

### Source-test owner bootstrap plateau (not packaged PHAR)

`SessionBootstrapProducerTest::testOwnerAndSpoolMemoryPlateausInSeparate128MProcesses`:

| Turns | Archive bytes | Peak bytes |
|---:|---:|---:|
| 256 | 1,216,632 | 73,924,608 |
| 4096 | 19,497,176 | 73,924,608 |

Separate cold 128M processes. Peak identical across ~16× archive growth. Label as
source-test evidence, not packaged PHAR proof.

## Limits of this baseline

- Automated pane readiness waits timed out when scrollback mixed historical
  transcript text with chrome. Resumes still completed and wrote checkpoints.
- Live RSS role-tree sampling by `HATFIELD_CWD` did not reliably see worker
  children. Prefer log `process_role` checkpoints until sampling is fixed.
- `/agents-live` did not show archived completed children in this packaged resume
  path, so child-view open/close cleanup was not measured.
- Warm same-process compaction or completed-child accumulation was not driven.
  Cold prefix plateaus do not prove warm release.
- Process I/O figures from `/proc/<pid>/io` were not retained for the full matrix.
  Logical archive bytes are reported; physical block I/O was warm-pagecache.
- Host Datadog role matching requires deploying `ops/datadog/process.d/conf.yaml`
  and restarting the Agent. This measurement does not claim that deployment.

## Related telemetry

See [Datadog setup](datadog.md) for `process_role`, memory sampler fields, and
Process Check role markers.
