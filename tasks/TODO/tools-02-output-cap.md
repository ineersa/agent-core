# TOOLS-02 Implement OutputCap service for large tool output

## Goal
Implement reusable output capping and persistence for text-producing tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/OutputCap.php`.
- Follow the user's pi output-cap extension behavior:
  - code/default cap: 20,000 chars (~5k tokens)
  - docs cap: 50,000 chars for doc-like files (`.md`, `.txt`, `.toon` at minimum)
  - stale file max age: 24 hours
- Persist oversized full output under `.hatfield/tmp/output-cap/<session-prefix>-<random-hex>.txt`.
- Return either unchanged text or a capped notice containing char count, rough token estimate, saved path, and `head`/`grep` hint.
- Add cleanup for stale files older than 24h. If no explicit session-start hook exists yet, expose a public cleanup method and call it from service construction or first use; document the choice in code comments.
- Add focused PHPUnit tests.

Out of scope:
- Do not implement read/bash tools here.
- Do not implement `.hatfield` settings; use constants/private defaults for this rollout unless an obvious config mechanism already exists.

## Acceptance criteria
- `OutputCap` can cap/persist oversized text and return a model-facing notice with saved path and inspection hints.
- Small output is returned unchanged.
- Doc-like paths use the 50,000 char cap; other paths use 20,000 chars.
- Saved files are written under `.hatfield/tmp/output-cap/` and parent directories are created as needed.
- Cleanup deletes files older than 24 hours and leaves newer files intact.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-17T04:42:04.932Z
