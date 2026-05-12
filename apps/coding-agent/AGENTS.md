# Coding Agent Application

Symfony 8.1 HTTP-less console application that consumes `ineersa/agent-core` and `ineersa/tui-bundle`.

## Runtime setup

- Composer package: `ineersa/coding-agent` (`type: project`).
- Requires PHP `>=8.5` and Symfony 8.1 components (`console`, `dependency-injection`, `config`, `event-dispatcher`, `messenger`, `dotenv`, `yaml`).
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

Do not add back `FrameworkBundle`, `HttpKernel`, `public/index.php`, or FrameworkBundle-only config such as `config/packages/framework.yaml`.

## Command conventions

- CLI commands live in `src/CLI/`.
- Prefer Symfony 8.1 invokable commands with `#[AsCommand]` and `__invoke()`.
- Prefer console argument resolver attributes (`#[Argument]`, `#[Option]`, `#[MapInput]`) and service injection in `__invoke()` over manual `InputInterface` parsing when practical.
- Application-level TUI flow belongs in `src/TUI/InteractiveMode.php`; reusable TUI services/widgets belong in `packages/tui-bundle/`.

## Validation

```bash
php apps/coding-agent/bin/console list
php apps/coding-agent/bin/console agent:run
castor check
```
