# Domain architecture notes

Architecture maps for domain-level contracts are split by concern:

- `src/Domain/Message/AGENTS.md` — bus message taxonomy and routing ownership boundaries
- `src/Domain/Event/AGENTS.md` — event taxonomy, ordering constraints, and projection/listener topology

These AGENTS.md files complement TOON indexes (`ai-index.toon`, `docs/*.toon`) and should be kept in sync with runtime architecture changes.