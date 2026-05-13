# Agent Core Monorepo

This is the monorepo for `ineersa/agent-core` and its ecosystem.

## Workspaces

### [`packages/agent-core/`][agent-core-lib]
Core library: agent loop, domain model, contracts, infrastructure adapters, Symfony AI bridge.
It is framework-agnostic and must not depend on `symfony/http-kernel` or `symfony/framework-bundle`.

### [`packages/tui-bundle/`][tui-bundle]
Symfony 8.1 TUI component integration bundle. This is an HTTP-less bundle based on
`Symfony\Component\DependencyInjection\Kernel\AbstractBundle` and `symfony/tui`.

### [`apps/coding-agent/`][coding-agent-app]
Symfony 8.1 HTTP-less CLI application that consumes both packages. CLI commands, tool implementations,
extension loader, session persistence, and application-level TUI wiring live here.

## Development

```bash
castor install    # Install all dependencies
castor check      # QA across all workspaces
castor lib:check  # QA for agent-core library only
```

## Symfony setup

- The application and TUI bundle target Symfony 8.1 HTTP-less architecture.
- `apps/coding-agent` boots with `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` + `KernelTrait`.
- `apps/coding-agent/bin/console` uses `Symfony\Component\Console\Application` with the kernel container as the third constructor argument.
- `apps/coding-agent/config/bundles.php` registers `Symfony\Component\Console\ConsoleBundle` and `Ineersa\TuiBundle\TuiBundle`.
- `ConsoleBundle` pulls in `ServicesBundle` via Symfony's `#[RequiredBundle]` chain.
- Do not reintroduce `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config for the console app.
- Commands should prefer Symfony 8.1 invokable command style (`__invoke()`) and console argument resolvers over manual `InputInterface` parsing when practical.
- Configuration files in `apps/coding-agent/config/` should prefer YAML over PHP. The only PHP config file kept is `config/bundles.php` (required by Symfony for bundle registration); all other settings use YAML.

## Architecture boundaries

| Layer | Location | Owns |
|-------|----------|------|
| Core library | `packages/agent-core/` | Domain model, pipeline, contracts, in-memory stores |
| TUI rendering | `packages/tui-bundle/` | Symfony TUI integration, terminal engine, keybindings, themes, widgets |
| Application | `apps/coding-agent/` | HTTP-less CLI app, commands, tools, extensions, session, TUI wiring |

## Runtime architecture

The app follows a strict layered boundary:

- `src/TUI/` depends only on `Runtime/Contract`, `Runtime/Protocol`, `TuiBundle`, and `Symfony Tui`.
- `src/Runtime/Contract/` and `Protocol/` define the canonical runtime event/command DTOs and the `AgentSessionClient` interface.
- `src/Runtime/InProcess/` and `Process/` implement `AgentSessionClient` using agent-core services or a subprocess.
- `src/CLI/` wires everything together via the single `agent` command.

The TUI must **never** import `Ineersa\AgentCore\Application`, `Ineersa\AgentCore\Infrastructure`, or `Symfony\Component\Messenger` directly.

Boundary enforcement is automated via Deptrac in the coding-agent Castor QA.
Run: `castor dev:deptrac` from `apps/coding-agent/` or `castor check` from root.

## AGENTS.md map

All architecture documentation files in the monorepo:

| File | Scope |
|------|-------|
| [`apps/coding-agent/AGENTS.md`][coding-agent-app] | HTTP-less Symfony 8.1 console app setup, boot flow, command conventions |
| [`packages/tui-bundle/AGENTS.md`][tui-bundle] | Symfony TUI bundle setup, dependency rules, service wiring direction |
| [`packages/agent-core/AGENTS.md`][agent-core-lib] | Library overview: namespace responsibilities, architecture map links |
| [`packages/agent-core/src/Application/AGENTS.md`][app-arch] | Command→handler topology, message dispatch flow, event projectors, observability wiring |
| [`packages/agent-core/src/Domain/AGENTS.md`][domain-arch] | Domain model index: links to Message and Event sub-documents |
| [`packages/agent-core/src/Domain/Message/AGENTS.md`][domain-msg] | Bus message taxonomy: command, execution, and publisher payloads |
| [`packages/agent-core/src/Domain/Event/AGENTS.md`][domain-event] | Event lifecycle taxonomy, ordering constraints, projection sinks |
| [`packages/agent-core/src/Infrastructure/Doctrine/AGENTS.md`][infra-doctrine] | Doctrine persistence schema migration notes |
