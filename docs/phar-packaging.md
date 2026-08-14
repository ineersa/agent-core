# PHAR packaging

Platform-neutral `hatfield.phar` for hosts with PHP ≥ 8.5 and required extensions.

Related: [distribution.md](distribution.md) · [static-packaging.md](static-packaging.md)

## System PHP requirements

Keep synchronized across `composer.json` `ext-*`, `bin/console` PHAR guard,
`installer/bash-installer`, and this doc:

- `php` ≥ 8.5
- `ext-pdo_sqlite`, `ext-mbstring`, `ext-xml`, `ext-intl`, `ext-curl`, `ext-openssl`,
  `ext-pcntl`, `ext-posix`, `ext-tokenizer`, `ext-ctype`, `ext-filter`, `ext-iconv`, `ext-phar`

`tools/static/pin.json` may list a deliberate SFX **superset** for native builds — not identical to this system-PHAR list.

## Castor tasks

```bash
castor phar:build
castor phar:ensure
castor phar:clean
castor phar:info
castor docs:validate
```

| Variable | Meaning |
|---|---|
| `HATFIELD_PHAR_PATH` | Override PHAR output path |
| `HATFIELD_PHAR_STAGING_DIR` | Override staging dir |
| `HATFIELD_PHAR_BOX_BIN` | Override Box binary |
| `HATFIELD_BUILD_VERSION` / `HATFIELD_BUILD_COMMIT` | Embedded build identity |
| `HATFIELD_BINARY_PATH` | Runtime/test executable override |

## Build pipeline

Implemented in `.castor/helpers.php`:

1. Fresh staging of `bin/`, `src/`, `config/`, `migrations/`, public Extension API package, and **selected** built-in docs.
2. Selected docs are Markdown files marked `builtin: true` under:
   - `docs/*.md`
   - `.hatfield/extensions/extension-api/docs/*.md`
3. Docs are copied as regular files at those canonical paths (no `internal-docs/`, no full unmarked `docs/` tree).
4. Embed build identity; staging Composer `--no-dev`; Box compile; smoke.

Freshness (`phar:ensure`) fingerprints the complete packaged input set including each selected doc file. Changing a marked doc invalidates the PHAR; unmarked repository docs do not need to be staged.

## Runtime model

Writable state lives under the runtime CWD (`.hatfield/`), not inside the archive.
`AppResourceLocator` resolves bundled defaults/themes/docs from the app root (`phar://…` when running the archive).

## Tests

```bash
castor test --filter=PharSmokeTest
castor test --filter=HatfieldDocsToolTest
```

PHAR smoke asserts exact selected docs as regular files and absence of `internal-docs/` and unmarked docs.
