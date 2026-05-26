# TOOLS-02 Implement OutputCap service for large tool output

## Goal
Implement reusable output capping and persistence for text-producing tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/OutputCap.php`.
- Follow the user's pi output-cap extension behavior with constructor/configurable defaults so TOOLS-R04 can wire values from Hatfield settings:
  - code/default cap: 20,000 chars (~5k tokens)
  - docs cap: 50,000 chars for doc-like files (`.md`, `.txt`, `.toon` at minimum)
  - stale file max age: 24 hours
- Persist oversized full output under `.hatfield/tmp/output-cap/<session-prefix>-<random-hex>.txt`.
- Return either unchanged text or a capped notice containing char count, rough token estimate, saved path, and `head`/`grep` hint.
- Add cleanup for stale files older than 24h. If no explicit session-start hook exists yet, expose a public cleanup method and call it from service construction or first use; document the choice in code comments.
- Add focused PHPUnit tests.

Out of scope:
- Do not implement read/bash tools here.
- Do not implement `.hatfield` settings here; expose constructor/configuration inputs with safe defaults. TOOLS-R04 owns hydrating these values from Hatfield settings.

## Acceptance criteria
- `OutputCap` can cap/persist oversized text and return a model-facing notice with saved path and inspection hints.
- Small output is returned unchanged.
- Doc-like paths use the configurable doc cap defaulting to 50,000 chars; other paths use the configurable default cap defaulting to 20,000 chars.
- Saved files are written under `.hatfield/tmp/output-cap/` and parent directories are created as needed.
- Cleanup deletes files older than the configurable retention defaulting to 24 hours and leaves newer files intact.
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
