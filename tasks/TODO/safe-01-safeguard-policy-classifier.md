# SAFE-01 SafeGuard policy store and classifier

## Goal
Plan: `.pi/plans/extension-tool-hooks-safeguard-plan.md`

Port the deterministic SafeGuard policy/rule layer from the Pi extension concept into Hatfield PHP code. This task builds the classifier and policy loading only; it should not depend on real tool execution yet.

Depends on: none strictly, but should follow the plan's SafeGuard policy section.

Scope:
- Add SafeGuard policy model, policy store, path matcher, command matcher, and classifier.
- Read policy from Hatfield locations: project `<cwd>/.hatfield/safe-guard.json` and home `~/.hatfield/safe-guard.json`.
- Keep default protected read and dangerous command patterns active when no policy file exists.
- Model hard blocks separately from policy-relaxable rules.

## Acceptance criteria
- Classifier hard-blocks `sudo`/privilege-escalation style commands regardless of policy.
- Classifier identifies dangerous bash commands such as `rm`, `rmdir`, `git clean`, `git reset --hard`, `git push --force`, and `git push -f`.
- Classifier identifies environment exposure commands such as `env` and `printenv`.
- Classifier identifies sensitive reads such as `.env.local`, `auth.json`, `.ssh/id_*`, cloud credentials, `*.pem`, and `*.key`.
- Classifier identifies writes/edits outside cwd by resolving target paths against cwd.
- Policy files can relax configured non-hard-block categories without disabling hard blocks.
- Unit tests cover classifier behavior without invoking real tools.
- Validation with Castor: `castor test --filter SafeGuard`; `castor deptrac`.

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
- Created: 2026-05-29T20:50:06.010Z
