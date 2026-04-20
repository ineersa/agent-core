# Agentic loop core

## Architecture notes

Relationship maps live in nested AGENTS.md files (not in TOON indexes):

- `src/Application/AGENTS.md`
- `src/Domain/AGENTS.md`
- `src/Domain/Message/AGENTS.md`
- `src/Domain/Event/AGENTS.md`

Use these for command/event/message topology (`command -> handler`, `event -> projector/listener`, `message -> dispatched-by/handled-by`).

## Operations docs

- Dashboard spec: `docs/operations/agent-loop-observability-dashboard.md`
- Alert rules: `docs/operations/agent-loop-alert-rules.yaml`
- On-call recovery runbook: `docs/operations/agent-loop-oncall-runbook.md`