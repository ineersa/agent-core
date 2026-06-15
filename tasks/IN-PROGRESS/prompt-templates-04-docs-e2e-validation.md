# PT-04 Prompt templates docs, E2E smoke, and final validation

## Goal
Reference plan: `.pi/plans/prompt-templates-implementation-plan.md`.

Scope:
- Depends on PT-01, PT-02, and PT-03.
- Add/finish user-facing prompt-template documentation and settings docs.
- Add product-level integration or E2E coverage that exercises a real template invocation through the implemented flow where practical.
- Perform final validation of the feature as a whole.

This is intentionally last; docs can be drafted earlier, but final smoke/validation waits until runtime and TUI integration have landed.

## Acceptance criteria
- `docs/prompt-templates.md` documents Hatfield locations, top-level `prompts: []`, lowercase filename canonicalization, placeholder syntax, non-recursive discovery, first-wins collisions, CLI flags, ignored `argument-hint`, and non-MVP package/extension support.
- `docs/settings.md` and `.hatfield/settings.yaml` example content stay in sync for the `prompts: []` key.
- Integration/E2E coverage or documented smoke steps verify `/template args` expands through the final runtime/TUI path and transcript follows normal expanded prompt projection.
- No docs claim support for `prompts.paths`, `prompts.enabled`, `-np`, `argument-hint`, package manifests, extension-provided templates, or raw `/template args` transcript preservation.
- `LLM_MODE=true castor check` passes on the integrated feature; if prerequisites are unavailable, keep the task IN-PROGRESS and record the blocker rather than moving to CODE-REVIEW.
- Task metadata records final validation results and any deferred follow-ups discovered during smoke testing.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/prompt-templates-04-docs-e2e-validation
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation
Fork run:
PR URL:
PR Status:
Started: 2026-06-15T00:19:50.699Z
Completed:

## Work log
- Created: 2026-06-09T00:10:20.146Z

## Task workflow update - 2026-06-15T00:19:50.699Z
- Moved TODO → IN-PROGRESS.
- Created branch task/prompt-templates-04-docs-e2e-validation.
- Created worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/prompt-templates-04-docs-e2e-validation.
