# Agentic loop core

## Architecture notes

Relationship maps live in nested READMEs (not in TOON indexes):

- `src/Application/README.md`
- `src/Domain/README.md`
- `src/Domain/Message/README.md`
- `src/Domain/Event/README.md`

Use these for command/event/message topology (`command -> handler`, `event -> projector/listener`, `message -> dispatched-by/handled-by`).