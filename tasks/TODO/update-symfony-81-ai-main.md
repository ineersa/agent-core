# Update Symfony 8.1 stable + Symfony AI dev-main

## Goal
## Summary

Two-part dependency update:
1. **Symfony 8.1**: Bump from `8.1.0-BETA1` to `8.1.0` stable release
2. **Symfony AI**: Point `symfony/ai-platform`, `symfony/ai-agent`, `symfony/ai-generic-platform` to `dev-main` (currently ahead of `v0.9.0` by the upcoming 0.10 generic-platform release)
3. **Remove include_usage hack** once Symfony AI dev-main is confirmed working

## Current State

### Symfony
- Installed: `v8.1.0-BETA1` (released 2026-05-05)
- Stable: `v8.1.0` (released 2026-05-29)
- Constraints already `^8.1` — just need `composer update` to pick up stable

### Symfony AI
- Installed: `v0.9.0` for all three packages
- Need: `dev-main` to get unreleased `stream_options` / `include_usage` support in generic-platform 0.10

## Changelog Analysis

### Symfony 8.1.0-BETA1 → 8.1.0 stable
**BC BREAKs in UPGRADE-8.1.md (relevant to this project):**

1. **Console** `[BC BREAK]` — `$default` type changed to `mixed` in `InputArgument`, `InputOption`, `#[Argument]`, `#[Option]`. Low risk since we use invokable commands.
2. **Messenger** — Serializers return `Envelope<MessageDecodingFailedException>` on decode failure instead of throwing. Receivers no longer delete messages on decode failure. `ReceiverInterface::get()` has new `$fetchSize` argument. Deprecate `StopWorkerOnTimeLimitListener`.
3. **FrameworkBundle** — Deprecate `Bundle::registerCommands()` (we use `#[AsCommand]`). Deprecate `senders` nesting in messenger routing config. Deprecate `framework.http_cache.terminate_on_cache_hit`. Deprecate `framework.profiler.collect_serializer_data`.
4. **DependencyInjection** — Deprecate default index/priority methods for tagged locators/iterators (use `#[AsTaggedItem]`). Deprecate named autowiring aliases without `#[Target]`.
5. **Serializer** — Deprecate datetime constructor as fallback. `PartialDenormalizationException` signature change.
6. **VarExporter** — Deprecate `Hydrator` and `Instantiator` classes.
7. **Validator** — Deprecate `ConstraintValidatorInterface::initialize()` and `validate()` in favor of `validateInContext()`.

**Security fixes in RC1/stable:**
- CVE-2026-48747: Mailer webhook signature pinning
- CVE-2026-48761: HtmlSanitizer URL sanitization
- CVE-2026-48760: HtmlSanitizer BiDi marks in URLs
- CVE-2026-48736: IPv6 transition forms in private subnet checks
- CVE-2026-48489: Security failure path forwarding
- CVE-2026-48784: Routing dot-segment encoding

**Assessment**: Minor BC breaks only. Symfony 8.0→8.1 is a minor release per semver. The `^8.1` constraints will resolve to stable. Low risk.

### Symfony AI v0.9.0 → dev-main

**Already absorbed (v0.9.0 BC breaks we already handle):**
- `[BC BREAK]` Rework `AssistantMessage` to hold `ContentInterface` parts (variadic constructor) — **already done in codebase**
- `[BC BREAK]` Platform constructor changed to `(providers, modelRouter, eventDispatcher)` — **already using ProviderInterface**
- `[BC BREAK]` Replace variadic constructors with array params — **already adapted**
- `[BC BREAK]` Rename `#[With]` to `#[Schema]` — **already using Schema**

**New on dev-main (unreleased, will be 0.10):**

| Package | Change | Impact |
|---------|--------|--------|
| `ai-generic-platform` 0.10 | Request usage stats for streamed responses by default when no `stream_options` provided | **Removes our hack** in `LlmPlatformAdapter.php` line 74 |

**No unreleased BC breaks** in `ai-platform` or `ai-agent` — their changelogs on main still show `0.9` as the latest section.

## Implementation Steps

### Phase 1: Symfony 8.1 stable update
1. Run `composer update symfony/*` to resolve from BETA1 to 8.1.0 stable
2. Review any deprecation notices from test suite
3. Address FrameworkBundle messenger routing config deprecation if applicable (flat `senders` list)
4. Run `castor check` to validate

### Phase 2: Symfony AI dev-main
1. Change composer.json:
   - `symfony/ai-platform`: `^0.9` → `dev-main`
   - `symfony/ai-agent`: `^0.9` → `dev-main`  
   - `symfony/ai-generic-platform`: `^0.9` → `dev-main`
2. Run `composer update symfony/ai-platform symfony/ai-agent symfony/ai-generic-platform`
3. Verify the `stream_options` / `include_usage` behavior is now handled by the generic bridge

### Phase 3: Remove include_usage hack
1. In `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` line 74:
   - Remove the `array_replace($input->getOptions(), ['stream' => true, 'stream_options' => ['include_usage' => true]])` 
   - Replace with just `$input->getOptions()` merged with `['stream' => true]` (or whatever the stream option merge looks like)
2. Run tests to verify token usage still flows through `extractUsage()`

### Phase 4: Validate
- `castor check` (full validation)
- E2E test with llama.cpp:9052 to confirm streaming usage data still works

## Files to Modify

| File | Change |
|------|--------|
| `composer.json` | Update symfony/ai-* constraints to `dev-main` |
| `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` | Remove `include_usage` hack (line 74) |

## Blast Radius
- **30 production files** use Symfony AI types (scout report available)
- **1 file** needs the hack removal
- **Symfony update**: no code changes expected (minor version, BC promise)
- **Highest risk**: `LlmPlatformAdapter.php` — the primary adapter with 20+ Symfony AI imports

## Risks
- `dev-main` is a floating target — pin to a specific commit after testing if desired
- Unreleased AI code may have undocumented changes not yet in CHANGELOG
- The `stream_options` default behavior in generic-platform 0.10 needs verification: does it send `include_usage: true` automatically, or just handle the response when present?

## Acceptance criteria
- composer.json updated: Symfony AI packages pointing to dev-main
- composer.lock updated: Symfony packages at 8.1.0 stable (not BETA1)
- include_usage hack removed from LlmPlatformAdapter.php
- Token usage data still flows correctly in streaming (verified by test or manual E2E)
- castor check passes
- No new deprecation warnings in test suite

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
- Created: 2026-06-05T21:45:00.709Z
