# TUI Bundle

Symfony 8.1 HTTP-less bundle for reusable terminal UI integration on top of `symfony/tui`.

## Package setup

- Composer package: `ineersa/tui-bundle` (`type: symfony-bundle`).
- Requires PHP `>=8.5`.
- Depends on Symfony 8.1 components:
  - `symfony/console`
  - `symfony/dependency-injection`
  - `symfony/event-dispatcher`
  - `symfony/tui`
- Must not depend on `symfony/http-kernel` or `symfony/framework-bundle`.

## Bundle setup

- `src/TuiBundle.php` extends `Symfony\Component\DependencyInjection\Kernel\AbstractBundle`.
- The bundle declares `#[RequiredBundle(ConsoleBundle::class)]`, so hosts get the Console/Services bundle chain needed for console services.
- Service registration should be added through `loadExtension()` and package-local config files when the bundle grows.

## Ownership boundaries

This package owns reusable terminal UI infrastructure:

- Symfony TUI service factories and helpers
- reusable widgets/layouts
- themes/styles
- keybinding/event abstractions
- terminal rendering concerns

It should not own application-specific agent workflows, session policy, tool implementations, or command orchestration. Those belong in `apps/coding-agent/`, while agent loop/domain behavior belongs in `packages/agent-core/`.

## Development notes

- Keep this package HTTP-less.
- Prefer `Symfony\Component\Tui` APIs directly for terminal UI integration.
- If a service needs console infrastructure, depend on `ConsoleBundle`/DI services rather than FrameworkBundle conveniences.
