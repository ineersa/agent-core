# AI-03 Home settings bootstrap and comment-preserving settings writer

## Goal
Plan reference: .pi/plans/symfony_ai_platform_integration_plan.md#ai-03--home-settings-bootstrap-and-comment-preserving-settings-writer

Goal: support user defaults/favorites without destroying hand-written settings comments.

Depends on: AI-01.

Parallelism: can run alongside AI-02 and AI-08 after AI-01; unblocks AI-07 persistence.

Scope:
- On startup/config resolution, if `~/.hatfield/settings.yaml` is missing, initialize it from documented defaults/examples.
- Add home settings writer service for machine-managed changes: `ai.default_model`, `ai.default_reasoning`, later model favorites.
- Preserve existing comments and unrelated keys where possible.
- If perfect comment preservation is not possible with current YAML stack, constrain writes to targeted scalar replacements and fail safely rather than rewriting the whole file.

## Acceptance criteria
- Missing home settings file is created once.
- Updating model/reasoning does not remove existing comments from the file.
- Project `.hatfield/settings.yaml` remains the example/project override file; do not recreate `.hatfield.example/`.
- Suggested validation: `castor test --filter Settings`.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-03-home-settings-bootstrap-comment-preserving-writer
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-03-home-settings-bootstrap-comment-preserving-writer
Fork run: uzteevsbgpnz
PR URL:
PR Status:
Started: 2026-05-16T22:30:47.734Z
Completed:

## Work log
- Created: 2026-05-16T22:01:55.475Z

## Task workflow update - 2026-05-16T22:30:47.734Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-03-home-settings-bootstrap-comment-preserving-writer.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-03-home-settings-bootstrap-comment-preserving-writer.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-03-home-settings-bootstrap-comment-preserving-writer.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-03-home-settings-bootstrap-comment-preserving-writer.
- Summary: Starting Batch B task AI-03 after AI-01 completion: home settings bootstrap and comment-preserving writer.

## Task workflow update - 2026-05-16T22:32:11.163Z
- Recorded fork run: uzteevsbgpnz
- Summary: Launched implementation fork uzteevsbgpnz in worktree /home/ineersa/projects/agent-core-worktrees/ai-03-home-settings-bootstrap-comment-preserving-writer.
