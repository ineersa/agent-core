# SAFE-03 SafeGuard settings, docs, and validation

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Document and validate how users enable/configure the SafeGuard extension. Keep `.hatfield/settings.yaml` and `docs/settings.md` in sync.

Depends on: `SAFE-02`.

Scope:
- Document explicit enablement of the bundled SafeGuard extension via `extensions.enabled`.
- Document `.hatfield/safe-guard.json` and `~/.hatfield/safe-guard.json` policy locations.
- Document MVP noninteractive behavior: allow or block only; no approval prompt yet.
- Decide and encode default enablement policy. Recommended first choice from the plan: opt-in, not default-enabled.

## Acceptance criteria
- `docs/settings.md` explains enabling SafeGuard and policy file locations.
- Project `.hatfield/settings.yaml` remains in sync as local example config if changed.
- Docs clearly say the MVP blocks instead of prompting for approval.
- Tests cover config/container behavior as needed for the chosen enablement approach.
- Validation with Castor: `castor test --filter Config`; `castor check`.

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
- Created: 2026-05-29T20:50:20.133Z
