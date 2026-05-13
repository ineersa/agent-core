# Agent Core Monorepo

**Modular Monolith.** Single Composer application with logical module boundaries enforced by Deptrac.

## Source layout

```
src/
  AgentCore/       Core agent loop, domain model, contracts, infrastructure (Ineersa\AgentCore)
  CodingAgent/     Symfony 8.1 HTTP-less CLI app: commands, runtime, tools, extensions (Ineersa\CodingAgent)
  Tui/             Terminal UI: screens, widgets, theme, keybinding, renderer (Ineersa\Tui)

tests/
  AgentCore/       AgentCore test suite (Ineersa\AgentCore\Tests)
  CodingAgent/     CodingAgent test suite (Ineersa\CodingAgent\Tests)

bin/console        Single CLI entry point
config/            Symfony config (YAML preferred; only bundles.php is PHP)
castor.php         Castor task runner entry point
depfile.yaml       Deptrac boundary enforcement config
phpstan.dist.neon  PHPStan config (baseline in phpstan-baseline.neon)
.php-cs-fixer.dist.php  PHP CS Fixer config
```

## Development

```bash
castor install      # composer install
castor check        # Full QA: deptrac, phpunit, phpstan, cs-fixer check
castor deptrac      # Deptrac boundary enforcement
castor test         # PHPUnit tests
castor phpstan      # PHPStan static analysis
castor cs-fix       # PHP CS Fixer (fix in place)
castor cs-check     # PHP CS Fixer (dry-run check only)
```

## Symfony setup

- The application targets Symfony 8.1 HTTP-less architecture.
- Boots with `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `bin/console` uses `Symfony\Component\Console\Application` with the kernel container as the third constructor argument.
- `config/bundles.php` registers `Symfony\Component\Console\ConsoleBundle`.
- `ConsoleBundle` pulls in `ServicesBundle` via Symfony's `#[RequiredBundle]` chain.
- Do not reintroduce `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config.
- Commands should prefer Symfony 8.1 invokable command style (`__invoke()`) and console argument resolvers over manual `InputInterface` parsing when practical.
- Configuration files in `config/` should prefer YAML over PHP. The only PHP config file kept is `config/bundles.php` (required by Symfony for bundle registration); all other settings use YAML.

## Architecture boundaries

| Layer | Location | Owns | Must not depend on |
|-------|----------|------|--------------------|
| Core library | `src/AgentCore/` | Domain model, pipeline, contracts, in-memory stores | `CodingAgent`, `Tui`, `HttpKernel`, `FrameworkBundle` |
| TUI presentation | `src/Tui/` | Terminal UI: screens, widgets, theme, keybinding, renderer | `AgentCore`, `HttpKernel`, `FrameworkBundle` |
| Application | `src/CodingAgent/` | HTTP-less CLI app, commands, runtime boundary, tools, extensions, session, wiring | (may depend on both) |

## Runtime architecture

The app follows a strict layered boundary for runtime/TUI communication:

- `src/Tui/` depends on `CodingAgent/Runtime/Contract`, `CodingAgent/Runtime/Protocol`, and `Symfony Tui`.
- `src/CodingAgent/Runtime/Contract/` and `Protocol/` define the canonical runtime event/command DTOs and the `AgentSessionClient` interface.
- `src/CodingAgent/Runtime/InProcess/` and `Process/` implement `AgentSessionClient` using agent-core services or a subprocess.
- `src/CodingAgent/CLI/` wires everything together via the single `agent` command, delegating to `Ineersa\Tui\Application\InteractiveMode` for TUI mode.

The TUI must **never** import `Ineersa\AgentCore\Application`, `Ineersa\AgentCore\Infrastructure`, or `Symfony\Component\Messenger` directly.

Boundary enforcement: `castor deptrac` (runs `vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress`).

## AGENTS.md map

Architecture documentation within the source tree:

| File | Scope |
|------|-------|
| `src/AgentCore/Domain/AGENTS.md` | Domain model index, message and event sub-documents |
| `src/AgentCore/Domain/Message/AGENTS.md` | Bus message taxonomy: command, execution, and publisher payloads |
| `src/AgentCore/Domain/Event/AGENTS.md` | Event lifecycle taxonomy, ordering constraints, projection sinks |
| `src/AgentCore/Application/AGENTS.md` | Command→handler topology, message dispatch flow, event projectors, observability wiring |
| `src/AgentCore/Infrastructure/Doctrine/AGENTS.md` | Doctrine persistence schema migration notes |
| `.pi/plans/architecture_rollout_plan.md` | Architecture rollout plan and history |
