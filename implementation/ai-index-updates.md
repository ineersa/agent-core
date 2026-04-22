# AI Index Updates (Status)

This file tracks implementation status for the phased `.toon` index upgrades.

## Phase 3 (Implemented): DI Wiring Edges in `.toon`

### Goal
Add deterministic DI wiring metadata to class `.toon` files without relying on AST-only inference.

### Implemented approach
Use compiled Symfony container definitions as the wiring source of truth, then merge this metadata into per-class `docs/*.toon` files.

### Implemented output additions
```toon
wiring:
  serviceDefinitions:
    - serviceId: "..."
      visibility: "private"
      autowire: true
      autoconfigure: true
      file: "src/..."
      line: 42
      args:
        "$dep": "service(...)"
  aliases:
    - serviceId: "Contract\\FooInterface"
      target: "App\\Foo"
  injectedInto:
    - fqcn: "..."
      file: "src/..."
      line: 42
```

### Delivered changes
1. Added `scripts/export-wiring-map.php` to compile a Symfony container and export deterministic wiring metadata to `var/reports/di-wiring.toon`.
2. Added reverse-edge extraction (`injectedInto`) from resolved service references in compiled definitions.
3. Added alias extraction (`aliases`) from container aliases with alias-chain resolution.
4. Added service definition metadata (`serviceDefinitions`) including visibility/autowire/autoconfigure and normalized constructor-service args.
5. Wired export into `castor dev:index-methods` flow before method index generation.
6. Merged wiring payload into `scripts/generate-method-index.php` output pipeline under `wiring`.

### Notes
- `callgraph.json` remains method-call topology; it is not DI wiring topology.
- IDE call hierarchy/references remain investigation tools, not generation sources.
- File/line metadata is best-effort (class reflection location) when service-definition source coordinates are unavailable.

---

## Phase 4 (Removed): Summary Curation Workflow

Summary-centric workflow was intentionally removed for experimentation:

- `dev:summaries` Castor task was deleted.
- `scripts/generate-method-index.php` no longer reads or emits summary metadata.
- Namespace `ai-index.toon` files no longer include `summary` in `files` entries.
- Class docblock summary presence is no longer a quality gate.
- `.agents/summary-curator.md` was removed.

## Phase 5 (Implemented): Curated Description Maintainer Agent

Added `.agents/index-maintainer.md` to maintain curated AI index descriptions in:

- root `ai-index.toon`
- namespace `src/**/ai-index.toon`

The agent prompt now defines:

- which fields are curated vs generated
- one-sentence architecture-focused description style
- schema expectations for root and namespace index files
- required Castor regeneration/validation flow
