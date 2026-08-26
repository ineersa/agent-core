<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Prompt;

use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;

final readonly class WorkflowPrompt implements PromptContributorInterface
{
    public function __construct(
        private string $taskRoot,
    ) {
    }

    public function contribute(): string
    {
        $boardDesc = 'Task board at `'.$this->taskRoot.'` (external to code repo).';

        return '

## Project task workflow

Tasks live at an **external task board directory** (separate from this code repo) under TODO, IN-PROGRESS, CODE-REVIEW, DONE, ARCHIVE, and CANCELLED subdirectories. '.$boardDesc.'

Code operations (branches, worktrees, PRs) still run against this code repository.

- When claiming a task, call move_task with to="IN-PROGRESS". This requires a clean integration checkout (commit/stash first). It creates a task/<slug> git branch and sibling worktree at ../<repo>-worktrees/<slug>, copies vendor/ and .vera/ when they exist, updates parent worktree IDEA exclusions when present, creates minimal worktree-local `.idea` metadata from the integration primary module, opens that exact worktree in JetBrains via MCP (`ide_open_project` / agent-visible `jetbrains-index_ide_open_project`), then records metadata in the task file. IDE/MCP open failures are recorded as degradation notes and do not fail the transition.
- When implementation is complete and committed, the parent/orchestrator/user calls move_task with to="CODE-REVIEW". This automatically runs deterministic castor check (replay-backed, no live LLM) in the worktree, then pushes the branch and creates a GitHub PR via the gh CLI. The PR URL is stored in the task metadata. Run focused Castor validation (castor test, castor deptrac, castor phpstan, castor cs-check) yourself before moving to catch issues early.
- After code review and PR approval, the parent/orchestrator/user calls move_task with to="DONE". It attempts a git merge back into the integration checkout and reports conflicts without moving the task to DONE if the merge fails. After a successful merge, it runs git pull to sync with remote changes from GitHub PR merges. When cleanupWorktree is true, it closes the exact worktree JetBrains project before removal, then cleans worktree + IDEA exclusions; dirty preflight still fails closed before close.
- Use move_task with to="CANCELLED" from any status to abandon a task. Destination collisions fail before cleanup. Clean worktrees are closed in JetBrains then removed with IDEA exclusions; dirty worktrees fail closed without closing/removing; the git branch is left in place.
- move_task with to="DONE" requires a clean integration checkout by default. If it reports stale AD entries from staged additions deleted in the worktree, retry with cleanupStaleIndexEntries=true; do not commit unrelated staged changes just to satisfy the task workflow.
- **Task status/metadata moves are NOT committed to the code repository.** Task changes affect the external task board files only. The task board repo is independently versioned — no auto-commits to agent-core git history.
- For read-only reconnaissance, use **Explorer** for simple, bounded, mechanical evidence gathering and **Scout** for hard/broad contextual, dependency, architecture, impact, or reasoning-heavy investigation. Recon agents gather evidence only; they do not implement. Preserve parallel batches for independent work and use sequential launches only for dependent work.
- Prefer JetBrains semantic IDE tools (`jetbrains-index_ide_*` agent-visible names; raw MCP wire names remain `ide_*`) for navigation, references/hierarchy, diagnostics, and semantic rename/move. Always target the exact task worktree with `project_path`; never assume the integration checkout or the aggregate sibling-worktree project. move_task opens the exact worktree after claim; if IDE tools are unavailable, fall back to absolute-path filesystem operations. Parent IDEA exclusions still keep the aggregate worktrees project from indexing generated worktree content.
';
    }
}
