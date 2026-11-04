# Hatfield task-workflow extension

Native Hatfield port of the pi `task-workflow` extension. It registers task board tools, slash commands, and system prompt guidance. It installs the `task-workflow` skill into the project.

This package requires `ineersa/hatfield-extension-api` for `Ineersa\Hatfield\ExtensionApi\*` contracts. In this monorepo that dependency is a path repository; released consumers install the published API package.

## Runtime loading

`ExtensionManager` requires `.hatfield/extensions/vendor/autoload.php` to autoload project extension classes. After cloning or pulling this repository (or updating extension packages), refresh **both** autoload contexts from the **repository root**:

```bash
composer install
composer install -d .hatfield/extensions
```

- **Root `composer install`** — updates the host `vendor/autoload.php` when root `composer.json` maps extension namespaces (e.g. for tests and Castor QA).
- **`composer install -d .hatfield/extensions`** — installs path packages and creates `.hatfield/extensions/vendor/autoload.php`, which Hatfield loads at startup when `extensions.enabled` lists this extension.

If dependencies are already installed and only autoload maps changed, `composer dump-autoload` at the root is sufficient for (1); you still need (2) whenever `.hatfield/extensions/vendor/` is missing or stale.

Enable `Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension` in `.hatfield/settings.yaml` under `extensions.enabled`, then **start a new Hatfield session** — extensions register at startup; an existing TUI session will not show new tools or slash commands until restart.

See `docs/settings.md` (`extensions.enabled`, `extensions.settings.task_workflow`) for configuration.

## Skill install

On `register()`, the extension installs or refreshes the project skill at `.hatfield/skills/task-workflow/`:

- create the skill directory when absent
- refresh the whole bundled skill tree when the installed `SKILL.md` frontmatter `version` is missing or differs from the package, removing superseded files such as the old `references/` tree
- leave same-version installs untouched so local edits survive

Host skill discovery loads the installed copy from `.hatfield/skills/`. The extension does not register a duplicate package copy. Host discovery ignores unknown frontmatter keys such as `version`.

The consolidated skill is a main-agent procedure. Implementation forks receive the implementation specification, not workflow administration.

## Board setup

Use the checkout and resolved board from the active project workflow prompt; task tools use the same resolver. Configure `extensions.settings.task_workflow.task_root`; `HATFIELD_TASK_WORKFLOW_ROOT` takes precedence. Without either, the extension discovers a valid sibling `<checkout-name>-tasks` board. Keep installed guidance portable: never bake resolved paths into it or infer a board from another project's instructions. Board metadata stays outside the code repository; the user may commit it separately.

## Worktree and IDE setup

Moving to IN-PROGRESS creates the task worktree and branch, copies `vendor/`, updates parent IDEA exclusions when present, and creates minimal local `.idea` metadata. No IDE server is required. DONE or CANCELLED cleanup removes exclusions after successful worktree removal. Run task-branch checks in that exact checkout.

## Transition failure operations

A failed CODE-REVIEW transition reports the failing lane, bounded error, QA report directory, and lane log when one ran. Setup, lock, preflight, and finalizer failures report the actual error and available QA directory; do not invent a lane or log. Do not quarantine flakes, retry blindly, or raise timeouts. Fix the deterministic cause, document unrelated fixes, obtain review at the new target, and rerun the transition. Ask the user when a fix requires a broader product decision.

Partial failures after side effects, such as a successful push followed by failed PR creation, keep the task IN-PROGRESS. Inspect actual task path/status, Git state, and PR state. Record completed steps, the failed step and bounded cause, available QA reports, session/run identity, and a safe next action without blindly repeating completed steps. Redact credentials and raw authentication output.

DONE merges the task branch into the integration checkout and runs `git pull`. A conflict leaves the task in CODE-REVIEW; do not force it. Integration validation remains required after a successful merge, even if task status is already DONE. Record any failure or missing prerequisite rather than claiming completion.
