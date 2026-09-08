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
- refresh the whole bundled skill tree, including `references/`, when the installed `SKILL.md` frontmatter `version` is missing or differs from the package
- leave same-version installs untouched so local edits survive

Host skill discovery loads the installed copy from `.hatfield/skills/`. The extension does not register a duplicate package copy. Host discovery ignores unknown frontmatter keys such as `version`.

Tracked-work fork resume ownership rules live in this skill, not in global `fork` or `agent_resume` tool text.
