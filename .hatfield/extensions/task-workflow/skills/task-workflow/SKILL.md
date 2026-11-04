---
name: task-workflow
description: "Main-agent procedure for tracked work, review, and delivery."
version: 1.1.9
---

# Task workflow

## Establish current state

Main alone uses this procedure and task tools; implementation forks implement their assignments and return results. Use the resolved checkout and external board from the project workflow context. Never commit board metadata to the code repository.

Run `task_list`, read the task and applicable project instructions, and inspect Git status, log, and diff. After context loss, recover the task, Git state, and active children before continuing. Reuse current findings. Main investigates affected code, callers, boundaries, and tests; read-only scouts may resolve bounded unknowns. Resolve behavior, design, and public API decisions before implementation. Keep a concise implementation plan in the task record. Map additions to finalized requirements; do not add speculative APIs, compatibility, or fallback behavior.

For a planning-only request, present the plan, risks, open decisions, and validation, then stop without editing or changing task state.

## Implement

Move the task to IN-PROGRESS before changing code. Main keeps 1–2-file one-offs and delegates larger cohesive slices to implementation forks. Settle behavior and design first, then follow the permanent fork assignment guidelines. Routine local inspection and implementation details remain the fork's judgment.

Keep one writer per checkout. Main alone tracks child identity and progress in workflow metadata, without ownership handshakes or mandatory log formats. Before replacement, verify the previous writer has stopped; carry forward necessary current draft facts, not its operational history. Use eligible existing children rather than duplicates, subject to tool lifecycle limits.

Inspect returned changes and run focused Castor validation at the lowest useful layer, following the testing skill. Reuse proof for the same revision and environment; broaden checks only after changes, failures, or unresolved concerns. Main owns commits and records meaningful results and blockers with task tools. For an implementation-only request, report the result and stop before review or PR administration.

## Submit or update PR

Inspect the full task diff. For PR feedback, read the PR and inline comments, distinguish blockers from suggestions, move to IN-PROGRESS, and implement accepted fixes using the same assignment rule.

Obtain independent full-change review from the `reviewer` subagent at the committed target SHA. Send only `Review commit <sha>.` before a PR exists, or `Review PR <url> at commit <sha>.` for PR follow-ups. The reviewer definition owns assignment, evidence, scope, and verdict rules. Record reviewer identity, baseline, target, decision, validation, and blockers in workflow metadata, not the request. Approval must cover the full task change at the same revision being submitted; fix-only confirmation or an earlier approval is insufficient. Resolve blockers and repeat review as needed.

Call `move_task(to="CODE-REVIEW")` only when ready. This transition owns the full `castor check` gate, clean-tree check, push, and PR creation/update; do not run a separate full gate first. If a transition fails, inspect actual task/Git/PR state and completed side effects before acting. Record the bounded failure, available QA artifacts, and safe next action; do not blindly repeat completed steps. See the extension README for failure operations.

## Finish

Merge only with user authorization and confirmed PR approval or an already-merged PR. Call `move_task(to="DONE")`; leave conflicts unresolved rather than forcing a merge. Run required integration `castor check` in the integration checkout separately from the pre-merge gate. Record and report failures or unavailable prerequisites as incomplete post-merge validation even if status is already DONE. Confirm clean Git state and task-worktree removal before reporting completion.

## Scope

Honor explicit planning-only and implementation-only endpoints. For an end-to-end request, continue through the authorized endpoint rather than stopping after a local implementation. Never infer merge authorization. Report a concrete blocker when unable to continue safely. Board/IDE setup and transition operations live in the extension README; development and test-safety rules apply to every agent.
