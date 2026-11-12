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

After installing and enabling the extension, ask **“set up task workflow”**.
The bundled skill supplies setup guidance even before a repository is configured;
task tools and commands remain unavailable until configuration is loaded.
Follow the same steps manually below. Setup prepares the project, not a real task:
do not create, migrate, transition or merge production tasks to demonstrate setup.

### 1. Inspect the project and prerequisites

Start in a Git repository with a committed primary checkout, a GitHub repository
and a configured remote. Install Git and a current `gh` CLI with issue-artifact
support; use the existing `gh` authentication for the correct host. Inspect the
remote, default/base branch, project instructions, CI, dependency installation,
build and test commands before proposing settings or scripts. Do not assume a
particular language, package manager or validation command.

Preserve existing settings, templates and hook customizations. Determine what
task-local resources the project needs and which resources cleanup may own;
ask only about unresolved product/review policy, resource ownership or required
authorization, not facts that can be read from the checkout. Credentials remain
in `gh` authentication, never in project settings or scripts.

### 2. Prepare owner-level GitHub issue fields and permissions

Use the repository owner's **issue fields**, not GitHub Projects fields or labels.
For a personal repository the definitions belong to the personal account; for an
organization repository they belong to the organization. The production default
is a **single-select field named `Workflow`**, with these five options exactly:

`TODO`, `IN-PROGRESS`, `CODE-REVIEW`, `DONE`, `CANCELLED`.

If the owner already has a suitable field with another name (for example,
`Hatfield Status`), set `workflow_field` to that exact name and reuse it. Do not
require a rename or create a duplicate Workflow field. Other fields such as
GitHub's native Priority, Effort or dates are independent; leave them untouched.

Colors are cosmetic; choose any available colors without making them a setup
decision. Issue types are independent and are not a prerequisite for this
workflow. Existing native Task/Bug/Feature types can be kept as-is; do not recreate
them or add an Experiment type just to use the experiment Markdown template.
Templates structure issue bodies, not Workflow statuses or issue types.
Pin Workflow in the issue UI if useful for visibility, including on
issues without a type; pinning is not required for runtime correctness.

Manual setup is often the least-privilege route:

- **Personal account:** avatar → **Settings** → **Planning** → **Issue fields** →
  **New field**. Create Workflow as single-select and add all five options.
- **Organization:** organization **Settings** → **Planning** → **Issue fields**;
  an authorized organization administrator can create the same field/options.

See GitHub's [personal account UI announcement](https://github.blog/changelog/2026-11-11-issue-types-and-fields-are-available-for-personal-accounts).
Do not replace unrelated fields/options or reset values on existing issues.

Definition management and task writes have different permissions. With classic
OAuth tokens, personal-owner **definition reads** need `read:user`; creating or
updating those definitions needs the broader `user` scope. Organization
definitions require the corresponding organization permissions. **Per-issue
Workflow value operations**, ordinary issue edits and artifact operations use
their repository/API permissions; owner-definition write access is not a
prerequisite when the complete field already exists. Manually creating all five
options lets initialization reuse them without writing owner definitions.
See the [personal issue-field API permissions](https://docs.github.com/en/rest/users/issue-fields).
Do not automatically refresh credentials or request broader account scopes.
Explain the missing permission and manual setup alternative, then obtain human
authorization for any credential change; manual setup still requires readable
definitions and the repository permissions needed for normal task operations.

### 3. Configure the project, preserving existing settings

Merge this example into the project's `.hatfield/settings.yaml`; do not replace
the whole file. Values and timeouts are project choices, not universal defaults:

```yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
  settings:
    task_workflow:
      repository: owner/repository  # [HOST/]OWNER/REPO
      remote: origin
      base_branch: main
      worktree_root: ../project-worktrees
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

`repository` is the explicit GitHub identity; `remote` is the existing Git remote,
`base_branch` the intended PR target, `workflow_field` the exact existing field
name (default `Workflow`), and `merge_method` must respect project review/merge
policy. Relative `worktree_root` and hook paths resolve from the
**primary Git checkout**, not a task worktree. There is no automatic task-root
discovery or filesystem task-store fallback.

### 4. Prepare all three project-owned Bash hooks

Inspect and reuse the project's established commands. Commit scripts at the
configured paths, each with a positive `timeout_seconds`:

| Hook | Project responsibility |
| --- | --- |
| `setup` | Install/provision dependencies and uniquely owned task-local resources. |
| `validate` | Run the established pre-review build/test/lint checks for the committed revision; propagate failures. Never substitute a no-op for validation. |
| `cleanup` | Remove **only resources owned by this task**. Be idempotent and handle partial setup, never-started setup and a missing task directory. |

An explicit `exit 0` setup or cleanup script is acceptable when that phase has
nothing to do. Do not remove shared databases, containers, caches or other
checkouts just because they exist. Use the stable task resource ID/data directory
for ownership where needed. Hooks must not merge PRs, update Workflow or remove
Git worktrees; Hatfield owns those operations.

Scripts run noninteractively through Bash; only exit zero succeeds. Setup and
validation use the task worktree as cwd; cleanup uses the stable primary checkout.
All scripts are read from that primary checkout. Each timeout is also limited by
the enclosing invocation budget. See the [hook contract](#hook-contract) for the
complete environment, including `HATFIELD_PROJECT_ROOT`, `HATFIELD_TASK_WORKTREE`,
`HATFIELD_TASK_RESOURCE_ID` and persistent `HATFIELD_TASK_DATA_DIR`. Quote these as
data; never interpolate issue text into executable shell source. Design cleanup
before provisioning resources, not after a task fails.

### 5. Prepare shared Markdown issue templates

Preserve or prepare `.github/ISSUE_TEMPLATE/feature.md`, `bug.md` and
`experiment.md`. These are ordinary Markdown templates with `name` and `about`
YAML frontmatter, shared by the GitHub issue chooser and Hatfield. They are not
issue forms, and the extension does not require fixed headings. Keep them short:

| Template | Useful prepared-body content |
| --- | --- |
| Feature | Intended result, scope, acceptance criteria, approach/decisions, test plan, actual QA (initially not run). |
| Bug | Expected/actual behavior, reproduction/evidence, known root cause or labeled uncertainty, fix plan, tests and actual QA. |
| Experiment | Question/hypothesis, bounded method, success/stop criteria, planned verification and findings/actual QA. |

Fill the selected template and submit its prepared Markdown body to `create_task`,
not a filename. Strip frontmatter; do not combine `gh issue create --template`
with `--body-file`. Do not create unnecessary plan artifacts or cosmetic metadata.

All templates use the PR lifecycle. An experiment can finish with reviewed
findings or a negative result; an unknown bug root cause is valid at creation.
Result-only investigations without a PR are not a completion path in this version.

### 6. Commit, reload and deliberately initialize

Commit the settings, scripts and templates to the stable primary checkout with
the project's normal authorization. GitHub's issue chooser reads templates from
the default branch; publish them through the usual review process. Restart
Hatfield after extension enablement/code changes and reload changed configuration
by starting a fresh host before initialization; `/new` in an already running host
does not reload extension PHP classes. The setup skill is installed at extension
registration; task tools/commands are registered only with a configured repository.

With permission to initialize, run **`/tasks-init`**. This is deliberate: it checks
current CLI/artifact capabilities and resolves owner-scoped Workflow identities;
it **can create missing field definitions/options**, preserving unrelated
configuration. If all five options were created manually, it reuses them.
Normal listing/reading does not initialize definitions or run scripts.

Initialization needs an **existing ordinary issue**, not a PR, for a read-only
artifact endpoint probe (searched in bounded repository pages). If the repository
has no suitable issue, report this prerequisite and defer initialization. Do not
silently create a dummy issue or real production task. Authentication or capability
failures are explicit refusals, never a local backend fallback. `/tasks-init` is
**not a full lifecycle test**: it does not prove hooks, PR review/merge or cleanup
have run. A live lifecycle exercise belongs in an explicitly authorized disposable
integration repository, not setup of production work.

### Readiness checklist

- Git primary checkout, repository/remote, base branch and `gh` host/auth inspected.
- Owner-level Workflow field and all five option names readable; required
  repository permissions available or manual authorization steps clearly listed.
- Existing settings preserved; all three project-owned scripts and shared
  templates prepared and committed in the stable checkout.
- Validation uses the project's real checks; cleanup ownership and idempotence
  are understood; hook cwd/environment/timeouts reviewed.
- Fresh host loaded configuration; `/tasks-init` outcome recorded (or its exact
  blocker reported). State separately which checks actually ran and which remain
  unrun. Do not claim end-to-end readiness from fixture tests or initialization.

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
Dependency provisioning, editor setup and validation commands belong to the
project's scripts, not the reusable extension. Explicit exit-zero setup/cleanup
scripts are valid for projects needing no work; validation must run real checks.

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
