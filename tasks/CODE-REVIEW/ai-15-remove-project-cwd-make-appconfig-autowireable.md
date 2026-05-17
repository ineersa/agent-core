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
Status: CODE-REVIEW
Branch: task/ai-15-remove-project-cwd-make-appconfig-autowireable
Worktree: /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable
Fork run: gh6p7vi5eree
PR URL: https://github.com/ineersa/agent-core/pull/16
PR Status: open
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

## Task workflow update - 2026-05-17T03:54:21.950Z
- Recorded fork run: 6abi1xrg60r8
- Summary: Launched fork 6abi1xrg60r8 to implement AI-15: delete AppConfigResolver, make AppConfig self-hydrating DI service (calls AppConfigLoader in constructor via getcwd()), remove $projectCwd from ~30 method signatures across ModelSelectionService, HatfieldSessionStore, InteractiveMode, SessionInitializer, ThemeFactory, AgentCommand.

## Task workflow update - 2026-05-17T04:38:23.636Z
- Summary: Fork 6abi1xrg60r8 wrote partial AI-15 changes partly to the wrong checkout and did not complete. Main checkout was reset clean. Worktree /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable contains partial changes: AppConfigLoader no longer takes projectCwd, AppConfigResolver deleted there, ModelSelectionService/HatfieldSessionStore partially updated. Remaining refs include AppConfigResolver in services.yaml and ThemeFactory, projectCwd in AgentCommand/InteractiveMode/SessionInitializer, and AppConfig is not yet constructor-self-hydrating/autowireable (fromLoader/fromArray remain). Relaunching fork with absolute-path-only instructions.

## Task workflow update - 2026-05-17T04:38:52.496Z
- Recorded fork run: m8yelzisoxjj
- Summary: Relaunched AI-15 rescue fork m8yelzisoxjj. Instructions require absolute paths under /home/ineersa/projects/agent-core-worktrees/ai-15-remove-project-cwd-make-appconfig-autowireable for every read/edit/write and `cd <worktree> && ...` for every bash command. Scope: finish self-hydrating AppConfig DI service, delete AppConfigResolver refs, remove projectCwd params from src, update tests, validate, commit/push only in worktree, verify main remains clean.

## Task workflow update - 2026-05-17T04:59:12.469Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ai-15-remove-project-cwd-make-appconfig-autowireable to origin.
- branch 'task/ai-15-remove-project-cwd-make-appconfig-autowireable' set up to track 'origin/task/ai-15-remove-project-cwd-make-appconfig-autowireable'.
- Created PR: https://github.com/ineersa/agent-core/pull/16
- Validation: php bin/console --no-interaction: passed; castor test: passed (329 tests, 8048 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations); castor phpstan: passed (0 errors); castor cs-check: passed; main checkout untouched by rescue fork
- Summary: AI-15 completed in worktree commit 2d39ad71 on branch task/ai-15-remove-project-cwd-make-appconfig-autowireable and pushed. AppConfig is now autowireable/self-hydrating via AppConfigLoader + AppResourceLocator, AppConfigResolver deleted, $projectCwd removed from 30+ method signatures/call sites, TuiSessionState cwd removed, ThemeFactory injects AppConfig directly, and config comments/services updated. Warning: AI-05 and AI-09 CODE-REVIEW branches still reference $projectCwd/AppConfigResolver patterns and will need conflict resolution after AI-15 lands.

## Task workflow update - 2026-05-17T19:57:42.084Z
- Recorded fork run: dfkadj622em0
- Summary: Launched fork dfkadj622em0 to address PR #16 review comments: throw if getcwd() fails instead of masking with '/', restore stripped comments/docs in AppConfigLoader, ModelSelectionService, and HatfieldSessionStore, keep AppConfig public readonly style as non-blocking preference unless trivial. Fork instructed to operate only inside AI-15 worktree with absolute paths and verify main checkout untouched.

## Task workflow update - 2026-05-17T20:07:47.572Z
- Validation: php bin/console --no-interaction: passed; castor test: passed (329 tests, 8048 assertions, 1 pre-existing notice); castor deptrac: passed (0 violations); castor phpstan: passed (0 errors); castor cs-fix + castor cs-check: passed; castor check: passed (quality: ok); main checkout verified clean by fork
- Summary: PR #16 review comments addressed by fork dfkadj622em0 in commit ef2dbfb9, pushed to branch task/ai-15-remove-project-cwd-make-appconfig-autowireable. Changes: AppConfig and AppConfigLoader now throw RuntimeException if getcwd() fails instead of falling back to '/', restored stripped comments/docs in AppConfigLoader, ModelSelectionService, and HatfieldSessionStore, regenerated phpstan-baseline.neon. Public readonly vs getters review note intentionally left unchanged as non-blocking preference.

## Task workflow update - 2026-05-17T20:31:36.474Z
- Recorded fork run: gh6p7vi5eree
- Summary: Launched fork gh6p7vi5eree for second round of PR #16 review fixes: restore AppConfig conceptual class docs, remove AppConfig::fromArray() and Reflection/Closure constructor-bypass test helper from production, redesign tests to use production constructor/temp config fixtures, add AGENTS.md instruction forbidding production APIs/helpers solely for tests and constructor/property bypasses, restore AppConfigLoader relative-path comment.
