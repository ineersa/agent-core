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

## Phase 4 (Deferred): Summary Quality via Strict Agent (No CI Semantic Parser)

### Goal
Improve summary terminology quality using a strict, repeatable agent workflow instead of parser-based semantic lint.

### Approach
Create a dedicated summary-curator agent with namespace-specific rules and deterministic edit scope.

### Planned agent behavior
1. Read changed PHP classes.
2. Evaluate class docblock first summary sentence against namespace rules.
3. Rewrite only summary sentence when terminology/responsibility is weak or wrong.
4. Leave tags (`@param`, `@return`, etc.) intact.
5. Re-run `LLM_MODE=true castor dev:summaries` and `LLM_MODE=true castor dev:check`.

### Rule examples
- `src/Domain/Event/**`: summary should use event terminology.
- `src/Domain/Message/**`: avoid describing message DTOs as domain events unless explicitly intended.
- `Execute*` message classes: describe as execution message/command/effect payloads.

### Guardrails
- CI remains deterministic and enforces presence/format (`dev:summaries`, `dev:check`).
- Agent improves wording quality; it does not become a hard semantic gate.
