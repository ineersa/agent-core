# Hatfield castor-llm-mode extension

Native Hatfield port of the pi `castor-llm-mode` extension. For bash tool calls that invoke Castor, it prepends LLM-friendly environment exports (`LLM_MODE`, `CASTOR_DISABLE_VERSION_CHECK`, `NO_COLOR`, `CLICOLOR`) and normalizes `castor list` to `castor list --format=md --short --no-ansi` (including `vendor/bin/castor list` and list occurrences separated by `&&`, `||`, or `;`).

`ExtensionApi` types (`Ineersa\Hatfield\ExtensionApi\*`) are provided by the host application at runtime and are not duplicated in this package.

The rewrite hook runs in the host pre-event phase **before** SafeGuard, so SafeGuard evaluates the rewritten bash command.

The pi extension at `.pi/extensions/castor-llm-mode.ts` remains in place intentionally; this package is the Hatfield-native equivalent.

## Runtime loading

`ExtensionManager` requires `.hatfield/extensions/vendor/autoload.php` to autoload project extension classes. After cloning or pulling this repository (or updating extension packages), refresh **both** autoload contexts from the **repository root**:

```bash
composer install
composer install -d .hatfield/extensions
```

Enable `Ineersa\HatfieldExt\CastorLlmMode\CastorLlmModeExtension` in `.hatfield/settings.yaml` under `extensions.enabled`, then **start a new Hatfield session** — extensions register at startup.

See `docs/settings.md` (`extensions.enabled`, `extensions.settings.castor_llm_mode`) for configuration.
