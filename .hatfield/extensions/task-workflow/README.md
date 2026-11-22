# GitHub task workflow

GitHub issues hold specifications, plans, decisions, tests and QA results.
The single-select **Workflow** field is authoritative: `TODO`,
`IN-PROGRESS`, `CODE-REVIEW`, `DONE`, `CANCELLED`. Open/closed state does not prove
local cleanup. There is no separate task repository, owner record, work log, fork
history, reviewer form, mandatory artifact or ARCHIVE status.

Authenticated `gh` handles GitHub operations; linked branches/PRs identify work
independently of mutable titles. Local ownership/recovery checkpoints in the Git
common directory are shared by worktrees, not a task store.

## Project setup

After installing/enabling the extension, ask **“set up task workflow”** or follow
the steps below. The bundled skill works before repository configuration; task
tools/commands require loaded configuration. Setup must not create, migrate,
transition or merge production tasks.

### 1. Inspect the project and prerequisites

Require Git, a committed primary checkout, a GitHub repository/remote and current
`gh` with issue-artifact support, authenticated for the correct host. Inspect the
remote, base branch, project instructions, CI, dependency, build and test commands
before proposing settings/scripts; do not assume a language, package manager or
validation command.

Preserve settings, templates and hooks. Establish task-local resource needs and
cleanup ownership. Ask only unresolved product/review/ownership or authorization
questions, not observable checkout facts. Keep credentials in `gh`, never settings
or scripts.

### 2. Prepare owner-level GitHub issue fields and permissions

Use the personal/organization repository owner's **issue fields**, not Projects
fields or labels. Default: single-select `Workflow` with exactly the five options
listed above. Reuse a suitable existing field (e.g. `Hatfield Status`) by setting
`workflow_field` to its exact name; do not rename or duplicate it. Leave unrelated
fields (Priority, Effort, dates, etc.) untouched.

Colors are cosmetic; choose available colors without asking. Issue types are
optional and independent: keep existing Task/Bug/Feature types; do not recreate
them or add Experiment for a Markdown template. Templates structure bodies, not
statuses/types. Optionally pin Workflow in the issue UI, even on untyped issues;
runtime correctness does not require pinning.

Manual setup is often the least-privilege route:

- **Personal account:** avatar → **Settings** → **Planning** → **Issue fields** →
  **New field**. Create Workflow as single-select and add all five options.
- **Organization:** organization **Settings** → **Planning** → **Issue fields**;
  an authorized organization administrator can create the same field/options.

See GitHub's [personal account UI announcement](https://github.blog/changelog/2026-11-11-issue-types-and-fields-are-available-for-personal-accounts).
Do not replace unrelated fields/options or reset values on existing issues.

Classic OAuth personal-owner **definition reads** require `read:user`; definition
writes require `user`. Organization definitions need corresponding organization
permissions. Per-issue Workflow writes, issue edits and artifacts need their
repository/API permissions, not owner-definition write access when the complete
field exists. Manually creating all five options avoids definition writes.
See the [personal issue-field API permissions](https://docs.github.com/en/rest/users/issue-fields).
Never automatically refresh credentials or broaden scopes. Explain missing
permissions and manual setup, then obtain authorization for credential changes.
Manual setup still requires readable definitions and normal repository permissions.

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
| `validate` | Run established checks at review for the committed revision and at completion for the merged base in the primary checkout; propagate failures. Never substitute a no-op for validation. |
| `cleanup` | Remove **only resources owned by this task**. Be idempotent and handle partial setup, never-started setup and a missing task directory. |

An explicit `exit 0` setup or cleanup script is acceptable when that phase has
nothing to do. Do not remove shared databases, containers, caches or other
checkouts just because they exist. Use the stable task resource ID/data directory
for ownership where needed. Hooks must not merge PRs, update Workflow or remove
Git worktrees; Hatfield owns those operations.

Scripts run noninteractively through Bash; only exit zero succeeds. Setup and
review validation use the task worktree as subprocess cwd; cleanup and completion
validation use the stable primary checkout. This never changes the agent's cwd.
All scripts are read from that primary checkout. Each timeout is also limited by
the enclosing invocation budget. See the [hook contract](#hook-contract) for the
complete environment, including `HATFIELD_PROJECT_ROOT`, `HATFIELD_TASK_WORKTREE`,
`HATFIELD_TASK_RESOURCE_ID` and persistent `HATFIELD_TASK_DATA_DIR`. Quote these as
data; never interpolate issue text into executable shell source. Design cleanup
before provisioning resources, not after a task fails.

### 5. Prepare shared Markdown issue templates

During agent-guided setup, prepare the project's `.github/ISSUE_TEMPLATE/feature.md`,
`bug.md` and `experiment.md` from this package's defaults in
[`templates/issue/`](templates/issue/). Copy missing files; inspect and merge useful
body structure into existing files without overwriting customization. These defaults
ship with the extension, so no Agent Core checkout is needed. `/tasks-init` does
not create or replace template files. These are ordinary Markdown templates with `name` and `about`
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

The extension package itself can be installed at project or user scope; its actual
Composer package path is not assumed to be under this project's `.hatfield` directory.
At registration, the installer mirrors the canonical package skill, this `README.md`
and `templates/issue/` into the primary checkout's
`.hatfield/skills/task-workflow/`. The installed skill links to its adjacent README
and templates, not back into a project-specific vendor path. Those are managed
copies, not a second source guide: edit the package assets to change bundled guidance.
The version is published last; same-version local customization is preserved.

The extension registers that primary skill directory through the public skill API
before the unconfigured return, so setup is discoverable from linked worktrees too.
New worktrees use this registered canonical directory without copying skill files.
Auto-discovered local skills have higher precedence than registered directories;
an existing versioned task-workflow copy in the current worktree is refreshed using
the installer semantics so stale managed guidance cannot shadow the canonical skill.
Unversioned/unrelated copies are left alone, and host discovery precedence is unchanged.

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
  source Workflow. `to: "TODO", adopt: true` tracks an issue without starting work
  or reopening it. On TODO it is a no-op.
  Select ambiguous native PR/branch relationships explicitly. `acceptedHead`
  identifies the inspected/reviewed completion revision, required without a local
  submitted-SHA checkpoint. It is not merge authorization or a review bypass.

`/tasks`, `/tasks-todo`, `/tasks-in-progress`, `/tasks-code-review`, `/tasks-done`
accept an optional page. Native closure with active Workflow is displayed as
awaiting finalization. `/tasks-init` is deliberate project initialization.
The bundled skill covers planning-only, implementation-only, review, feedback
iteration and completion endpoints; separate project task prompts are unnecessary.
Main performs task administration and transitions; implementation forks are optional
bounded help, one writer per checkout. Hooks own the full validation gate; honor
project review and explicit merge authorization rather than repeating that gate
as a separate ceremony or inferring permission to merge.

## Transition order

| Transition | Behavior |
| --- | --- |
| TODO → IN-PROGRESS | Recover/create native `task/issue-N`, record owned path, create workspace, setup, publish last |
| IN-PROGRESS → IN-PROGRESS | Resume this machine's workspace; completed setup is reused for the same existing workspace |
| CODE-REVIEW → IN-PROGRESS | Return for changes using native PR head/base; provision/setup only if needed |
| IN-PROGRESS → CODE-REVIEW | Require committed clean workspace, validate actual SHA, recheck HEAD/cleanliness, push without force, verify remote and PR SHA, publish last |
| CODE-REVIEW → DONE | Protected GitHub merge/head guard, require confirmed MERGED, fast-forward actual merged base, cleanup, verified removal, validate merged base in primary checkout, close completed, publish last |
| TODO / IN-PROGRESS / CODE-REVIEW → CANCELLED | Reject merged PR, close/verify unmerged PR, cleanup even if never started, verified removal, recheck PR, close not planned, publish last |
| DONE → DONE / CANCELLED → CANCELLED | Reconcile unfinished local/remote finalization rather than returning early |

Other transitions are rejected. An active task without its pushed native branch
cannot be "resumed" by making a new branch from base. PR relationships replace
linked-branch relationships after PR creation; closed/merged native PR references
are paginated explicitly. Query failure is never interpreted as no match.

Review validates the submitted SHA. Hook failure/interruption, changed HEAD or a
dirty worktree blocks advancement. Commit formatter changes and revalidate.
Successful push/PR effects are reconciled after uncertain errors.

Both hosts retry new PR issue links for 15s. Missing links report the PR to retry;
identity checks stay strict.

Completion uses `gh pr merge --match-head-commit` and the configured merge method,
never `--admin`, local remerging, force push or `--delete-branch`. Repository
protections and project review requirements still apply. An enabled auto-merge
or queued merge is pending and retains the workspace. Squash/rebase completion
updates from the actual merged base, not the old implementation branch.
After cleanup and verified task-worktree removal, the existing validate hook runs
again with `HATFIELD_TASK_REASON=done` in the primary checkout. Only then may remote
DONE finalization proceed. A failure retains pending recovery and does not publish
DONE; retry the same finalization without recreating the worktree or merging again.

## Hook contract

Scripts are invoked as `bash --noprofile --norc /absolute/script`, noninteractively
through the existing cancellable execution interface. Inherited `BASH_ENV` is
neutralized. No issue content, branch or path is interpolated into shell source.
Scripts always come from the stable primary checkout. Setup (`HATFIELD_TASK_REASON=start`)
and pre-review validation (`HATFIELD_TASK_REASON=review`) run with the task workspace as subprocess cwd.
Cleanup runs in the primary checkout, including missing-worktree recovery; validate
at completion (`HATFIELD_TASK_REASON=done`) also runs there, after merge/base update,
cleanup and verified removal. These are subprocess cwd choices, not a change to
the agent's cwd. No new hook or settings are required. Hook updates must remain
compatible with active workspaces; scripts are not snapshotted.

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
worktree deletion. Hook diagnostics visible to the model in tool results retain
stdout and stderr tails independently, up to 2,000 characters each; they are not
full streamed hook output, and noisy stdout cannot displace stderr. Diagnostics
are not automatically appended to issues; summarize actual outcomes in QA.
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

A successful merge is not rolled back when cleanup or completion validation fails. Retain active Workflow
and machine recovery until finalization succeeds, even if GitHub auto-closed the
issue. Cleanup invalidates setup first, so partial teardown cannot be silently
resumed as implementation. Pending terminal operations must resume as the same
finalization; a verified concurrent merge during cancellation requires explicit
DONE reconciliation with cleanup evaluated for the new reason.

Checkpoints are atomic JSON under the Git common directory's `hatfield/tasks/`,
keyed by host/repository and issue. They track machine-local ownership and pending
recovery, not task specifications or a second task board; GitHub remains authoritative.
Every `move_task` transition callback takes a shared Git-common primary lock before
its issue lock, serializing transitions across linked worktrees through primary
updates and completion validation. This is cooperative local locking, not
distributed ownership across independent clones. One orchestrator writes a task. Re-read before
publication; stale-read detection is not an atomic compare-and-set guarantee.
External status/closure edits never execute hooks. Conflicting already-closed
issue reasons prevent terminal publication; do not pretend closing again changed
GitHub's reason. If removal succeeded but GitHub publication failed, retry remote
finalization without recreating workspaces or re-merging. Subsequent execution
must use the returned primary checkout rather than a removed directory.

## Claude Code plugin

The same package is a Claude Code plugin. It shares the project's
`.hatfield/settings.yaml` (user `~/.hatfield/settings.yaml` < project), hooks,
templates, GitHub Workflow field and Git-common-directory checkpoints with
Hatfield, so either agent can continue a task the other started.

```text
/plugin marketplace add ineersa/agent-core
/plugin install task-workflow@hatfield
```

Or, in one step: `/plugin install task-workflow --marketplace ineersa/agent-core`.

Requirements: `php` (8.3+) and `composer` on `PATH`, plus the authenticated `gh`
CLI. The first server start of each plugin version installs its own Composer
dependencies into the plugin data directory, so that start can take a minute;
reconnect with `/mcp` if it timed out.

- Tools: the five task tools above under the `task-workflow` MCP server, plus
  `run_command` for the Hatfield slash commands.
- Commands: `/task-workflow:tasks [status] [page]` and
  `/task-workflow:tasks-init`.
- Skill: the bundled `task-workflow` skill; the setup guide and issue templates
  are at the plugin root.
- The server is started once per Claude Code session. After changing settings,
  reconnect it with `/mcp`. Tool calls may run project hooks for up to their
  configured timeouts; the plugin allows each call up to one hour.
- Pressing Esc does not stop a running hook: the server finishes the transition
  and its usual recovery state applies.

## Cutover and integration

Finish legacy active tasks before switching an existing project. Import outstanding
TODO requirements, current plans, useful decisions and QA into GitHub. Keep the
old repository as read-only history and old-path-to-issue mappings only in one-off
migration output, never the new runtime. Configure/test hooks and templates once.
A dedicated integration repository, not production history, must exercise the live
lifecycle and failure paths before cutover. Fixture tests are not live GitHub proof.
