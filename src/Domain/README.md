# Domain architecture notes

Architecture maps for domain-level contracts are split by concern:

- `src/Domain/Message/README.md` — bus message taxonomy and routing ownership boundaries
- `src/Domain/Event/README.md` — event taxonomy, ordering constraints, and projection/listener topology

These READMEs complement TOON indexes (`ai-index.toon`, `docs/*.toon`) and should be kept in sync with runtime architecture changes.