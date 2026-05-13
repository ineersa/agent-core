# Coding Agent Application

Symfony 8.1 HTTP-less console application that consumes `ineersa/agent-core` and `ineersa/tui-bundle`.

## Runtime setup

- Composer package: `ineersa/coding-agent` (`type: project`).
- Requires PHP `>=8.5` and Symfony 8.1 components (`console`, `dependency-injection`, `config`, `event-dispatcher`, `messenger`, `dotenv`, `yaml`, `serializer`).
- Uses path repositories for:
  - `../../packages/agent-core`
  - `../../packages/tui-bundle`
- Keeps `minimum-stability: dev` + `prefer-stable: true` while Symfony 8.1 packages are pre-stable.

## HTTP-less Symfony boot

- `src/Kernel.php` extends `Symfony\Component\DependencyInjection\Kernel\AbstractKernel` and uses `KernelTrait`.
- `bin/console` boots the kernel and creates `Symfony\Component\Console\Application` with the kernel container as the third constructor argument.
- `config/bundles.php` registers:
  - `Symfony\Component\Console\ConsoleBundle`
  - `Ineersa\TuiBundle\TuiBundle`
- `ConsoleBundle` pulls in `ServicesBundle` through Symfony's `#[RequiredBundle]` mechanism.
- `config/services.php` loads both `App\*` and `Ineersa\AgentCore\*` namespaces for DI.
- `config/packages/` is pre-populated for messenger (three agent buses), serializer, and agent-core aliases.
- Configuration prefers YAML (`*.yaml`) over PHP (`*.php`). The only PHP config file kept is `config/bundles.php` (required by Symfony for bundle registration); all other settings use YAML.
- The `KernelTrait::configureContainer()` loads YAML and PHP configs identically, so there is no technical barrier to YAML.

Do not add back `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config such as `config/packages/framework.yaml`.

## Architecture layers

```
src/CLI/       — Single AgentCommand (TUI default, --headless for JSONL)
src/Runtime/  
  Contract/    — AgentSessionClient, RunHandle, StartRunRequest, UserCommand
  Protocol/    — RuntimeCommand, RuntimeEvent, JsonlCodec, RuntimeEventMapper
  InProcess/   — InProcessAgentSessionClient (in-process, default transport)
  Process/     — JsonlProcessAgentSessionClient, AgentProcessSupervisor (process skeleton)
src/TUI/       — InteractiveMode (receives AgentSessionClient), future screens/widgets
```

## Runtime boundary

TUI code may only depend on `App\Runtime\Contract`, `App\Runtime\Protocol`, `Ineersa\TuiBundle`, and `Symfony\Component\Tui`.
It must not import `Ineersa\AgentCore\Application`, `Ineersa\AgentCore\Infrastructure`, or `Symfony\Component\Messenger`.

The `InProcessAgentSessionClient` bridges agent-core services into the protocol layer.
The `RuntimeEventMapper` is the sole bridge between agent-core `RunEvent` and runtime `RuntimeEvent`.

## Command conventions

- Single `agent` command replaces the former `agent:chat`, `agent:run`, `agent:resume`, `agent:list`.
- Options:
  - `--headless` — JSONL protocol mode (stdin/stdout, for process transport)
  - `--transport=in-process|process` — transport selection (default: in-process)
  - `--prompt=TEXT` — initial prompt for TUI mode
  - `--resume=RUN_ID` — resume an existing run
- Default mode is interactive TUI via `InteractiveMode` + `InProcessAgentSessionClient`.
- Application-level TUI flow belongs in `src/TUI/InteractiveMode.php`; reusable TUI services/widgets belong in `packages/tui-bundle/`.

## Boundary enforcement

Deptrac config at `depfile.yaml` enforces layer isolation.

## QA with Castor

Coding-agent has its own Castor tasks in `apps/coding-agent/`.

```bash
# Run all coding-agent QA (deptrac + phpunit)
castor dev:check          # from apps/coding-agent/

# Individual tasks
castor dev:deptrac        # architecture boundary validation only
castor dev:test           # PHPUnit tests only

# From root — runs agent-core + coding-agent QA
castor check
```

## Validation

```bash
php bin/console list
php bin/console help agent
php bin/console agent --headless
vendor/bin/phpunit
castor dev:check
```
