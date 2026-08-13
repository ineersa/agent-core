# Domain architecture notes

Split by concern (paths relative to this file):

- `Message/AGENTS.md` — bus message taxonomy and routing ownership boundaries
- `Event/AGENTS.md` — event envelope, lifecycle types, projection/listener topology

Complement TOON indexes (`ai-index.toon`, `docs/*.toon`). Keep in sync when runtime architecture changes. Application producer/consumer map: `../Application/AGENTS.md`.
