# Agentic loop core

## Architecture notes

Relationship maps live in nested READMEs (not in TOON indexes):

- `src/Application/README.md`
- `src/Domain/README.md`
- `src/Domain/Message/README.md`
- `src/Domain/Event/README.md`

Use these for command/event/message topology (`command -> handler`, `event -> projector/listener`, `message -> dispatched-by/handled-by`).

## Operations docs

- Dashboard spec: `docs/operations/agent-loop-observability-dashboard.md`
- Alert rules: `docs/operations/agent-loop-alert-rules.yaml`
- On-call recovery runbook: `docs/operations/agent-loop-oncall-runbook.md`