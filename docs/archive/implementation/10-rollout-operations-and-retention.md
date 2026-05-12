# Stage 10 - Rollout, Operations, and Retention

## Goal
Ship safely with progressive rollout, explicit SLOs, and controlled storage growth.

## Rollout Plan

### Phase A - Internal alpha
- Enable for internal users only.
- Runtime mode: `inline` or single orchestrator queue.
- Disable parallel tools initially.

### Phase B - Controlled beta
- Enable Messenger execution workers.
- Enable parallel tools with low concurrency (2-4).
- Enable Mercure streaming for selected tenants.

### Phase C - Production
- Full orchestrator + execution worker split.
- Resume scanner enabled.
- Automated archive and retention jobs enabled.

## SLO Targets
- command apply latency p95 < 1s
- run start latency p95 < 2s
- turn commit latency p95 < 5s (excluding model latency)
- recovery success rate > 99.9%

## Retention Strategy

### Hot
- keep `agent_hot_prompt_state` only for active runs
- delete immediately when run reaches terminal status

### Warm
- keep `agent_runs`, `agent_commands`, `agent_turn_index` for 30-90 days

### Cold
- keep JSONL logs and artifacts in compressed archive
- retain based on policy or customer tier

## Archive Jobs
- `agent-loop:archive-completed-runs`
  - move old run logs to archive path or object storage
  - replace heavy payloads with refs
- `agent-loop:prune-db-events`
  - prune old optional detail events
- `agent-loop:cleanup-hot-state`
  - remove orphan/stale hot prompt rows

## Operational Safeguards
- dead-letter queues for command/execution buses
- alarm on stale `running` runs and lock contention
- alarm on high stale result rate
- alarm on JSONL write failures

## Security and Compliance
- encrypt artifact storage at rest
- redact sensitive payload fields before persistence
- tenant isolation in run/topic identifiers
- audit trail retained in append-only log

## Deliverables
- rollout feature flags
- SLO dashboards + alert rules
- archive/prune scheduled jobs
- incident response playbook

## Acceptance Criteria
- staged rollout completed without data loss incidents
- retention jobs verified in staging and prod
- storage growth trend remains within agreed budget
