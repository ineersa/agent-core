# Local bundle development in a real Symfony app

This guide shows how to wire your local `ineersa/agent-core` checkout into another Symfony app for fast development/testing.

## 1) Point the app to your local checkout (symlink or copy)

In the **target app** `composer.json`, add a `path` repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/home/ineersa/projects/agent-core",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "ineersa/agent-core": "*@dev"
  }
}
```

Then install/update:

```bash
composer update ineersa/agent-core -W
```

Notes:
- `"symlink": true` = live edits from local checkout are reflected immediately.
- `"symlink": false` = copy mode (similar to `link --copy` workflows).

### Optional helper script (`agent-core/link`)

This repository now includes a helper script modeled after Symfony's link workflow.

From this repository:

```bash
# Symlink local checkout into target app vendor/
./link /path/to/your/symfony-app

# Copy mode instead of symlink
./link /path/to/your/symfony-app --copy

# Rollback (remove vendor copy/link and clear cache)
./link /path/to/your/symfony-app --rollback
```

After rollback, reinstall from registry in the target app:

```bash
composer update ineersa/agent-core -W
```

## 2) Enable the bundle

In `config/bundles.php`:

```php
<?php

return [
    // ...
    Ineersa\AgentCore\AgentLoopBundle::class => ['all' => true],
];
```

## 3) Add minimal bundle config

Create `config/packages/agent_loop.yaml`:

```yaml
agent_loop:
  runtime: messenger
  streaming: mercure
  llm:
    default_model: gpt-4o-mini
```

API routes are auto-registered by the bundle when `api.enabled` is `true` (default). To disable:

```yaml
agent_loop:
  api:
    enabled: false
```

## 4) Pick transport mode for development

The bundle ships with in-memory transports by default. For a real app dev loop, choose one of these:

### A. Easiest local mode (fully synchronous)

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      agent_loop.command: 'sync://'
      agent_loop.execution: 'sync://'
      agent_loop.publisher: 'sync://'
```

This is easiest for manual testing because requests execute end-to-end without workers.

### B. Async mode (closer to production)

Use real transports (Doctrine/Redis/etc.) and run consumers.

Example consumer commands:

```bash
php bin/console messenger:consume agent_loop.command -vv
php bin/console messenger:consume agent_loop.execution -vv
php bin/console messenger:consume agent_loop.publisher -vv
```

## 5) Smoke test

Start a run:

```bash
curl -sS -X POST http://localhost/agent/runs \
  -H 'Content-Type: application/json' \
  -d '{
    "prompt": "Hello from local bundle dev",
    "metadata": {
      "tenant_id": "dev-tenant",
      "user_id": "dev-user",
      "session": {"env": "local"}
    }
  }'
```

Then inspect run summary:

```bash
curl -sS http://localhost/agent/runs/<RUN_ID>
```

## 6) Dev workflow tips

- If you change DI wiring/config in the bundle, clear app cache:
  - `php bin/console cache:clear`
- If using symlink mode and autoload gets stale:
  - `composer dump-autoload`
- To go back to released package behavior:
  1. remove the `path` repository,
  2. pin a normal version in `require`,
  3. run `composer update ineersa/agent-core -W`.
