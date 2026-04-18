# Symfony Agent Loop Bundle - Implementation Plan

This directory contains a staged implementation plan for building a Symfony bundle with JS-style agent loop capabilities and Symfony AI integration.

## Stages

1. `00-bundle-setup.md`
2. `01-js-parity-contracts.md`
3. `02-runtime-domain-and-reducer.md`
4. `03-persistence-hot-cold-storage.md`
5. `04-orchestrator-and-workers.md`
6. `05-symfony-ai-integration.md`
7. `06-tool-execution-hitl-and-parallelism.md`
8. `07-steering-cancel-continue-resume.md`
9. `08-api-and-mercure-streaming.md`
10. `09-testing-observability-and-debugging.md`
11. `10-rollout-operations-and-retention.md`
12. `11-reference-schemas-and-event-examples.md`

## Reading Order

Follow numeric order. Stage 01 locks contracts, Stage 02 defines the execution model, and Stage 03 defines storage before worker fan-out is built.

## Implementation Notes

- Keep one writer per run.
- Keep DB as source of truth; project JSONL/streaming from outbox.
- Keep hot prompt state temporary and rebuildable.
- Add core extensibility seams now (commands, hooks, events) so higher-level features can be layered without core rewrites.
- Ensure all contracts are validated with golden payload tests before adding optimizations.
