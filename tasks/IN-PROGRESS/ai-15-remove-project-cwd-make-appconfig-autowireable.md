# AI-15 Remove $projectCwd, make AppConfig autowireable

## Goal
## Goal

Eliminate `$projectCwd` from all service method signatures. `AppConfig` becomes autowireable — calls `AppConfigLoader::load()` in its constructor using `getcwd()`. `AppConfigResolver` is deleted.

## Impact summary (from scout)

### Services that carry `$projectCwd` parameters

| File | Methods affected |
|------|-----------------|
| `AppConfigResolver` | **DELETE entire class** |
| `AppConfigLoader::load(defaultsPath, projectCwd)` | Remove `$projectCwd` — use `getcwd()` internally |
| `AppConfigLoader::resolveConfigPaths(data, projectCwd)` | Remove `$projectCwd` — use `getcwd()` |
| `ModelSelectionService` | 6 methods + catalog() — all drop `$projectCwd` |
| `HatfieldSessionStore` | ~10 methods — all drop `$projectCwd` |
| `InteractiveMode::run()` | Drop `$projectCwd` param |
| `SessionInitializer::initialize()` / loadTranscriptEntries() | Drop `$cwd` / `$projectCwd` |
| `ThemeFactory::create()` | Drop `$cwd` |
| `AgentCommand::__invoke()` | Remove `$projectCwd` local variable |
| `InProcessAgentSessionClient` | `initializeSessionsBasePath()` — review cwd source |
| `ConfigProvider` interface + adapter (AI-09 worktree) | Drop `$projectCwd` |
| `ConfiguredSymfonyAiPlatformFactory` (AI-05 worktree) | Drop `$projectCwd` |
| `SymfonyAiProviderFactory` (AI-05 worktree) | Drop `$projectCwd` |

### Call sites of `AppConfigResolver::resolve()` → become `$appConfig->catalog` or similar

| File | Line | Replacement |
|------|------|-------------|
| `HatfieldSessionStore::getSessionsDir()` | 269 | `$this->appConfig->sessions` directly |
| `ModelSelectionService::catalog()` | 227 | `$this->appConfig->catalog` |
| `ModelSelectionService::resolveInitialReasoning()` | 137 | `$this->appConfig->ai` directly |
| `ModelSelectionService::changeReasoning()` | 205 | `$this->appConfig` for bootstrap |
| `ThemeFactory::create()` | 42 | `$this->appConfig->tui` directly |

### New `AppConfig` design

```php
final class AppConfig
{
    public readonly string $cwd;
    public readonly TuiConfig $tui;
    public readonly array $sessions;
    public readonly ?AiConfig $ai;
    public readonly array $raw;
    public readonly ?HatfieldModelCatalog $catalog;

    public function __construct(
        private AppConfigLoader $loader,
        private AppResourceLocator $resources,
    ) {
        $this->cwd = getcwd() ?: '/';
        // ... self-hydrate from loader->load()
    }
}
```

### DI changes

- **Remove:** `AppConfigResolver` service definition
- **Add:** `AppConfig` as a service (currently not registered)
- `%kernel.project_dir%` wiring for `SessionRunStore`, `SessionRunEventStore`, `HatfieldSessionStore` — keep or change to `getcwd()`?
- `%kernel.project_dir%` for `AppResourceLocator`, `SettingsPathResolver` — keep (installation root)

### Key question: `HatfieldSessionStore` fallback

`HatfieldSessionStore::getSessionsDir()` currently:
```php
$path = rtrim($projectCwd ?: $this->projectDir, '/').'/.hatfield/sessions';
```
When `$projectCwd` is removed, the fallback becomes `$this->appConfig->cwd` instead.

## Acceptance criteria
- `AppConfig` is autowireable — injectable anywhere without `AppConfigResolver` wrapper
- No `$projectCwd` parameters remain in any method signature
- `AppConfigResolver` class deleted
- All tests pass (need updating for constructor changes)
- `castor check` green
- Worktree branches AI-05 and AI-09 (CODE-REVIEW) will need merge conflict resolution after this lands

## Acceptance criteria
- AppConfig is autowireable without AppConfigResolver
- No $projectCwd parameters in any method signature
- AppConfigResolver class deleted
- All existing tests pass with updated constructors
- castor check green

## Workflow metadata
Status: IN-PROGRESS
Branch: task/ai-15-remove-project-cwd-make-appconfig-autowireable
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable
Fork run:
PR URL:
PR Status:
Started: 2026-05-17T03:52:50.643Z
Completed:

## Work log
- Created: 2026-05-17T03:51:54.231Z

## Task workflow update - 2026-05-17T03:52:50.644Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ai-15-remove-project-cwd-make-appconfig-autowireable.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable.
