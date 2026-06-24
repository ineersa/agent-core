# QA Metrics — Before and After MAINT-05

This document records the before/after metrics for the MAINT-05 (A–G)
cardinal QA rework.  All "after" metrics are from the MAINT-05G branch
deterministic baseline.

## Before (pre-MAINT-05A)

Source: MAINT-05 scouting reports, task logs, and observed run timings.

| Metric | Before | Notes |
|--------|--------|-------|
| `castor check` wall time | ~180–240s | PHAR ensure (5-15s) + check_llm_generation_ready (4s) + 7 concurrent lanes, orchestrated through custom shard fan-out |
| Live LLM calls in `castor check` | 4+ | test:controller, test:llm-real, and implicit TUI live-LLM prompts |
| `castor test` CodingAgent sequential | ~131.9s | Custom 4-shard fan-out with per-method kernel boots |
| `castor test` full ParaTest | ~30–40s | Custom file-shard discovery replaced in MAINT-05B |
| `castor test:tui` wall time | ~120–180s | 11 test classes, ~12 tmux harness launches, live llama.cpp calls, sleeps |
| TUI harness launches per check | ~12 | One per test class with new tmux session each |
| Controller E2E in check | live only | Required llama.cpp; flaky under parallel load |
| PHAR in default QA | mandatory | check() ran phar_ensure() before everything |
| Stale worker risk | high | Custom file-shard fan-out had per-shard timeouts with leaked messenger:consume children |
| check_llm_generation_ready in check | yes | ~4s preflight before live-LLM lanes |
| Custom Castor shard discovery | yes | coding_agent_shard_groups + build_test_worker_command + build_test_variants_commands — ~200 lines |

## After (post-MAINT-05G, historical deterministic-only)

Source: metrics from MAINT-05G `castor check` and focused runs (before live `llm-real` was re-added to the gate).

| Metric | After | Notes |
|--------|-------|-------|
| `castor check` wall time | **~42s** | 6 deterministic lanes: deptrac (2s), ParaTest unit/integration (~12s), controller-replay (8s), TUI replay (16s), phpstan (3s), cs-check (1s); run concurrently via proc_open |
| Live LLM calls in `castor check` | **0** | All lanes use replay fixtures or pure static analysis |
| `castor test` CodingAgent sequential | **~56s** | Per-class kernel boot (MAINT-05F), data-provider consolidation |
| `castor test` full ParaTest | **~11–21s** | ParaTest as default (MAINT-05B), per-worker cache isolation |
| `castor test:tui` wall time | **~11s** | 3 tests, 35 assertions (TuiJourneyE2eTest + TuiStartupSnapshotTest) with replay fixtures |
| TUI harness launches per check | **2** | TuiJourneyE2eTest + TuiStartupSnapshotTest in one session |
| Controller E2E in check | replay only | ControllerReplaySmokeTest (1 test, 14 assertions) with fixture-driven SSE (MAINT-05D) |
| PHAR in default QA | **no** | PHAR is opt-in (castor phar:build, castor phar:ensure) |
| Stale worker risk | low (with nuance) | ParaTest + Castor session tracking; `cleanup_stale_check_workers` skips launcher ancestry and active Hatfield workers (`HATFIELD_SESSION_ID`); manual kills should target stale orphans only |
| check_llm_generation_ready in check | **no** | Only run by opt-in live commands |
| Custom Castor shard discovery | **removed** | ParaTest handles parallelism for both local dev and gate |
| `castor test:controller-replay` | **~8s** | Replay-backed, no live LLM |

† May be higher on first run due to doctrine:migration and cache warmup.  Measured at ~77s on a mid-range dev machine.

## Current baseline (llm-real in `castor check`)

Source: `task/llm-proxy-deterministic-live-tests` — live `llm-real` lane restored to the gate with ParaTest (`--processes=4`) and llama-proxy cache normalization on port 9052.

| Metric | Current | Notes |
|--------|---------|-------|
| `castor check` wall time | **~55–75s** (warm proxy) | 7 concurrent lanes: deptrac, ParaTest unit/integration, controller-replay, TUI replay, **test:llm-real** (~22–25s warm), phpstan, cs-check; `check_llm_generation_ready()` once (TTL cache 120s) |
| Live LLM in `castor check` | **yes** | Dedicated `test:llm-real` lane (8 tests); not implicit TUI live calls |
| `castor test:llm-real` (full) | **~22–25s** warm | ParaTest 4 workers; filtered runs stay sequential PHPUnit |
| check_llm_generation_ready in check | **yes** | Once before parallel lanes; cached skip on repeat within TTL |
| llama.cpp / proxy | **required** | Port 9052; proxy `cache_normalize_messages` recommended for stable cache keys |

The **post-MAINT-05G** table below remains the historical deterministic-only baseline (0 live LLM, ~42s) before this change.

## Opt-in live commands (unchanged behavior)

| Command | Requires | Used for |
|---------|----------|----------|
| `castor test:llm-real` | llama.cpp port 9052 | Provider compatibility smoke |
| `castor test:controller` | llama.cpp port 9052 | Live controller E2E (opt-in) |
| `castor llm:fixtures:record` | llama.cpp port 9052 | Re-record fixture deltas |

## Known remaining risks

- **Tmux requirement**: `castor check` and `castor test:tui` require tmux for TUI E2E lanes. If tmux is not installed, `castor check` fails immediately with a diagnostic instead of silently passing green. The orchestrator/user must install tmux before the gate can run.

- **Fixture staleness**: Replay fixtures recorded from the small `llama_cpp_test/test` model may drift from production provider behavior. Re-record with `castor llm:fixtures:record` on provider/prompt change.
- **Controller-replay fixture queue**: Multi-turn controller interactions require multiple fixtures in sequence; currently only single-turn fixtures exist.
