# GitHub task workflow

GitHub issues hold the current specification, implementation plan, decisions,
test plan and actual QA results. The five-value single-select **Workflow** field
is authoritative: `TODO`, `IN-PROGRESS`, `CODE-REVIEW`, `DONE`, `CANCELLED`.
GitHub open/closed state is lifecycle metadata, not proof of local cleanup.
There is no separate task repository, owner record, work log, fork history,
reviewer form, mandatory artifact, or ARCHIVE status.

All GitHub operations use the installed authenticated `gh` CLI. Native linked
branches and PRs provide development identity; task titles can change freely.
Only small machine-local ownership/recovery checkpoints live under the Git
common directory, shared by linked worktrees. They are not a second task store.

## Project setup

Configure `.hatfield/settings.yaml`:

```yaml
extensions:
  settings:
    task_workflow:
      repository: ineersa/agent-core  # [HOST/]OWNER/REPO
      remote: origin
      base_branch: main
      worktree_root: ../agent-core-worktrees
      workflow_field: Workflow
      merge_method: merge            # merge, squash, rebase
      hooks:
        setup:
          script: .hatfield/task-hooks/setup.sh
          timeout_seconds: 900
        validate:
          script: .hatfield/task-hooks/validate.sh
          timeout_seconds: 1800
        cleanup:
          script: .hatfield/task-hooks/cleanup.sh
          timeout_seconds: 300
```

Enable the extension through Hatfield extension settings. An unconfigured
repository registers no task tools; there is no automatic task-root discovery
or filesystem fallback. Relative paths resolve from the primary Git checkout,
not the current task directory. Credentials stay in `gh` authentication.

Commit feature, bug and experiment Markdown templates under
`.github/ISSUE_TEMPLATE/`. Templates are shared with GitHub's issue chooser
(which reads the default branch), not parsed forms. Projects may change their
headings. Use `name` and `about` frontmatter. Fill the selected template, strip
its frontmatter, and submit its prepared body to `create_task`; do not combine
`gh issue create --template` with `--body-file`.

All templates use the PR lifecycle. An experiment can finish with reviewed
findings or a negative result; an unknown bug root cause is valid at creation.
Result-only investigations without a PR are not a completion path in this version.

Commit all three hook scripts, then deliberately run `/tasks-init`. It validates
the current CLI/artifact capability and resolves owner-scoped Workflow field and
option identities, creating missing definitions/options while preserving unrelated
configuration. It requires an existing ordinary issue for a read-only artifact
probe. Only pin Workflow in the GitHub UI. Missing permissions or unsupported
capabilities fail explicitly; personal-owner field reads need `read:user`.
Normal listing/reading does not initialize definitions or execute scripts.

## Current content, not a diary

Keep intended behavior, scope, acceptance criteria, approach, consequential
decisions with rationale, planned tests and actual QA in the issue body. Update
changed understanding in place. For bugs include expected/actual behavior,
reproduction, evidence-based root cause or labeled uncertainty, and the fix plan.
Use comments for intentional discussion; fold accepted conclusions into the body.
Keep small tasks concise. A linked artifact is optional extended design/research,
not another mandatory copy of the plan. Artifacts are addressed by per-issue
number or canonical URL, never by ambiguous display name.

QA states what actually ran and remaining gaps. Unrun checks stay unrun; a passing
hook does not imply manual acceptance checks happened. There is no machine-readable
QA-report object or permanent task-level validation-passed flag.

## Tools and commands

- `create_task(title, body)`: prepared Markdown to a GitHub TODO issue. Frontmatter
  is stripped defensively. The result identifies a created issue even if its final
  Workflow publication fails, so recovery need not create a duplicate.
- `read_task(task, artifact?)`: current issue, native development links and local
  recovery. `task` is the immutable issue number or canonical URL. An explicit
  artifact adds that document; comment history is not fetched unnecessarily.
- `task_list(status?, page?, perPage?)`: defaults to active Workflow values,
  including native-closed issues. Filtered page applies independently to each
  requested status, up to `perPage` per status (maximum 100); at most 20 filtered
  pages are traversed. Uses actual field IDs in server-side GraphQL filters,
  never guessed field slugs or a scan of all historical issues. Local owned
  workspaces are separately bounded and remain visible despite remote status edits.
- `update_task(task, title?, body?, artifact?, comment?)`: edit current issue or
  explicitly selected artifact; optionally add intentional discussion. Does not
  change Workflow. No agent metadata, PR mirrors, summary/validation appenders
  or work-log arguments exist.
- `move_task(task, to, from?, adopt?, pr?, branch?, acceptedHead?, prTitle?, prBody?)`:
  transitions own hooks, Git/PR operations and recovery. `from` guards expected
  source Workflow. `adopt=true` explicitly adopts an unmanaged ordinary issue as
  TODO, including historical closed issues; native closure never implies cleanup.
  Select ambiguous native PR/branch relationships explicitly. `acceptedHead`
  identifies the inspected/reviewed completion revision, required without a local
  submitted-SHA checkpoint. It is not merge authorization or a review bypass.

`/tasks`, `/tasks-todo`, `/tasks-in-progress`, `/tasks-code-review`, `/tasks-done`
accept an optional page. Native closure with active Workflow is displayed as
awaiting finalization. `/tasks-init` is deliberate project initialization.
Task command prompts preserve planning-only, implementation-only, review,
feedback iteration and completion endpoints. Main performs task administration;
implementation forks are optional bounded help, one writer per checkout.

## Transition order

| Transition | Behavior |
| --- | --- |
| TODO → IN-PROGRESS | Recover/create native `task/issue-N`, record owned path, create workspace, setup, publish last |
| IN-PROGRESS → IN-PROGRESS | Resume this machine's workspace; completed setup is reused for the same existing workspace |
| CODE-REVIEW → IN-PROGRESS | Return for changes using native PR head/base; provision/setup only if needed |
| IN-PROGRESS → CODE-REVIEW | Require committed clean workspace, validate actual SHA, recheck HEAD/cleanliness, push without force, verify remote and PR SHA, publish last |
| CODE-REVIEW → DONE | Protected GitHub merge/head guard, require confirmed MERGED, fast-forward actual merged base, cleanup, verified removal, close completed, publish last |
| TODO / IN-PROGRESS / CODE-REVIEW → CANCELLED | Reject merged PR, close/verify unmerged PR, cleanup even if never started, verified removal, recheck PR, close not planned, publish last |
| DONE → DONE / CANCELLED → CANCELLED | Reconcile unfinished local/remote finalization rather than returning early |

Other transitions are rejected. An active task without its pushed native branch
cannot be "resumed" by making a new branch from base. PR relationships replace
linked-branch relationships after PR creation; closed/merged native PR references
are paginated explicitly. Query failure is never interpreted as no match.

Submission reruns validation for the submitted revision. Nonzero exit, launch
failure, timeout, cancellation, changed HEAD, tracked changes or nonignored
untracked files prevent advancement. A formatter's changes must be committed
and revalidated. Successful push/PR effects are reconciled after uncertain errors.

Completion uses `gh pr merge --match-head-commit` and the configured merge method,
never `--admin`, local remerging, force push or `--delete-branch`. Repository
protections and project review requirements still apply. An enabled auto-merge
or queued merge is pending and retains the workspace. Squash/rebase completion
updates from the actual merged base, not the old implementation branch.

## Hook contract

Scripts are invoked as `bash --noprofile --norc /absolute/script`, noninteractively
through the existing cancellable execution interface. Inherited `BASH_ENV` is
neutralized. No issue content, branch or path is interpolated into shell source.
Scripts always come from the stable primary checkout: setup/validate run with the
task workspace as cwd; cleanup runs with the primary checkout as cwd. Hook updates
must remain compatible with active workspaces; scripts are not snapshotted.

| Environment variable | Meaning |
| --- | --- |
| `HATFIELD_PROJECT_ROOT` | Stable primary checkout, source of scripts/config |
| `HATFIELD_TASK_REPOSITORY` | Explicit GitHub identity |
| `HATFIELD_TASK_NUMBER`, `HATFIELD_TASK_URL` | Stable issue identity |
| `HATFIELD_TASK_BRANCH`, `HATFIELD_TASK_BASE_BRANCH` | Implementation/target branches |
| `HATFIELD_TASK_WORKTREE` | Owned path, empty if never provisioned |
| `HATFIELD_TASK_WORKTREE_EXISTS` | Current directory existence: `1`/`0` |
| `HATFIELD_TASK_SETUP_ATTEMPTED` | Provisioning started: `1`/`0` |
| `HATFIELD_TASK_REASON` | `start`, `review`, `done`, `cancel` |
| `HATFIELD_TASK_RESOURCE_ID` | Unique stable local resource name across retries |
| `HATFIELD_TASK_DATA_DIR` | Persistent hook-owned data outside task workspace |

Only exit zero succeeds. Output saying "passed" does not override failure.
Timeouts honor both project and enclosing invocation budgets; destructive steps
wait for hook termination. Setup/cleanup tolerate retries and partial provisioning.
A crash can make hook outcome uncertain: exactly-once execution is not promised.
Never-started cancellation passes an empty worktree and setup-attempted `0`;
cleanup normally does nothing. Retained hook data allows cleanup after a task
directory disappears. It is deleted only after confirmed remote finalization.

Hooks own generated files/project resources, never PR merges, Workflow updates or
worktree deletion. Diagnostics are bounded tool output, not appended to issues.
Agent-core scripts own vendor/`.vera` copies, Composer/link repair and IDEA setup/
exclusions outside the reusable extension. Validation is `exec castor check`.
Explicit exit-zero setup/cleanup scripts are valid for projects needing no work.

## Deletion and recovery

After cleanup exits zero, ordinary **non-forced** `git worktree remove` must
succeed and both exact-path postconditions must hold:

1. No entry in `git worktree list --porcelain -z` for the expected repository.
2. No filesystem entry, including a dangling symlink or unexpected replacement.

A registered missing directory still requires targeted removal. A present
unregistered directory is preserved as a mismatch. No global prune, forced
removal, `rm -rf` fallback, automatic stash or branch deletion is performed.
Cleanliness is checked again after cleanup; DONE also guards the local committed
HEAD against the confirmed merged PR head. Cancellation retains branch/commits.
Primary/unrelated workspaces are never removal targets.

A successful merge is not rolled back when cleanup fails. Retain active Workflow
and machine recovery until finalization succeeds, even if GitHub auto-closed the
issue. Cleanup invalidates setup first, so partial teardown cannot be silently
resumed as implementation. Pending terminal operations must resume as the same
finalization; a verified concurrent merge during cancellation requires explicit
DONE reconciliation with cleanup evaluated for the new reason.

Checkpoints are atomic JSON under the Git common directory's `hatfield/tasks/`,
keyed by host/repository and issue. Cooperative locking detects local conflicts,
not distributed ownership. One orchestrator writes a task. Re-read before
publication; stale-read detection is not an atomic compare-and-set guarantee.
External status/closure edits never execute hooks. Conflicting already-closed
issue reasons prevent terminal publication; do not pretend closing again changed
GitHub's reason. If removal succeeded but GitHub publication failed, retry remote
finalization without recreating workspaces or re-merging. Subsequent execution
must use the returned primary checkout rather than a removed directory.

## Cutover, integration and export

Finish legacy active tasks before switching an existing project. Import outstanding
TODO requirements, current plans, useful decisions and QA into GitHub. Keep the
old repository as read-only history and old-path-to-issue mappings only in one-off
migration output, never the new runtime. Configure/test hooks and templates once.
A dedicated integration repository, not production history, must exercise the live
lifecycle and failure paths before cutover. Fixture tests are not live GitHub proof.

A Git clone is not an issue backup. Export issue metadata/current body, Workflow
values, comment history and numbered artifact metadata/bytes separately:

```bash
bash .hatfield/extensions/task-workflow/bin/export-task.sh OWNER/REPO 123 NEW_OUTPUT_DIRECTORY
```

The command refuses an existing destination and marks success with `COMPLETE`.
Artifact pages are requested explicitly with a bound because their endpoint has
no Link header. Failed/limited exports remain visibly incomplete; exports are
not consumed as a runtime fallback or synchronization database.
