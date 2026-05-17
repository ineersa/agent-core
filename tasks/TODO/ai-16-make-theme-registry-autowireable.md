# AI-16 Make ThemeRegistry autowireable, absorb ThemeLoader

## Goal
## Goal

Consolidate theme loading into `ThemeRegistry`, make it autowireable, and simplify `ThemeFactory` to a thin wrapper.

## Current mess

```
ThemeFactory::buildTheme($name, $paths):
  $loader = new ThemeLoader()           ← manual construction
  // load user paths
  // load built-in path
  // deduplicate by name
  $registry = new ThemeRegistry(...)     ← manual construction
  return new DefaultTheme($registry->getOrThrow($name))
```

## Target

```
ThemeRegistry::__construct(AppConfig, AppResourceLocator):
  // self-loads all palettes from AppConfig->tui->themePaths + built-in path
  // deduplicates (user paths win)
  // stores in internal map

ThemeFactory::__construct(ThemeRegistry):
  // that's it

ThemeFactory::create(?TuiTheme $hint = null): TuiTheme:
  if $hint → return $hint
  $name = $this->appConfig->tui->theme   ← or access via registry
  return new DefaultTheme($this->registry->getOrThrow($name))
```

## Changes

### 1. Move `ThemeLoader` logic into `ThemeRegistry`

- Move `loadFile()` and `loadDirectory()` as private methods into `ThemeRegistry`
- Delete `src/Tui/Theme/ThemeLoader.php`

### 2. `ThemeRegistry` becomes autowireable

```php
final class ThemeRegistry
{
    private array $themes = [];

    public function __construct(
        AppConfig $appConfig,            // after AI-15 — autowireable
        AppResourceLocator $resources,   // already autowireable
    ) {
        $tuiConfig = $appConfig->tui;
        // Load user paths (AppConfig->tui->themePaths)
        // Load built-in path (AppResourceLocator->getBuiltinThemesPath())
        // Deduplicate: user paths override built-in
    }
}
```

**Architecture note:** `ThemeRegistry` in `Ineersa\Tui\Theme` depends on `Ineersa\CodingAgent\Config\AppConfig`. This is acceptable because `ThemeFactory` already does this. If we want to avoid the cross-layer dep, pass `TuiConfig` + theme paths instead of full `AppConfig`. Keep it pragmatic — `AppConfig` is the single source of truth.

### 3. Simplify `ThemeFactory`

```php
final class ThemeFactory
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly ThemeRegistry $registry,  // autowired
    ) {}

    public function create(?TuiTheme $hint = null): TuiTheme
    {
        if (null !== $hint) {
            return $hint;
        }
        return new DefaultTheme($this->registry->getOrThrow($this->appConfig->tui->theme));
    }
}
```

- **Delete** `buildTheme()` method
- **Delete** `$resources` dependency (registry handles it)
- **Drop** `$cwd` param (done by AI-15)

### 4. Update callers

- `InteractiveMode::run()` — inject `ThemeFactory`, call `$this->themeFactory->create($theme)` (no cwd)
- Any tests constructing `ThemeFactory`

### 5. Update tests

- `ThemeFactoryTest` — update constructor, remove `$resources`
- `ThemeRegistryTest` — update constructor to take `AppConfig` + `AppResourceLocator`
- `ThemeLoader` tests — move to `ThemeRegistry` test or delete

### 6. DI

- `ThemeRegistry` auto-discovered via `Ineersa\Tui\` namespace autowiring
- `ThemeFactory` auto-discovered (same)
- Remove any explicit `ThemeLoader` wiring if it exists

## Depends on

- AI-15 (AppConfig must be autowireable first)

## Acceptance criteria

- `ThemeLoader` class deleted
- `ThemeRegistry` self-loads palettes from config
- `ThemeFactory::buildTheme()` removed
- `castor check` green

## Acceptance criteria
- ThemeLoader class deleted, logic moved into ThemeRegistry
- ThemeRegistry is autowireable — loads palettes from AppConfig + built-in path
- ThemeFactory simplified — injects ThemeRegistry, no buildTheme(), no resources dep
- castor check green

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-17T04:19:51.135Z
