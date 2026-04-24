# Symfony Flex recipe for `ineersa/agent-core`

This repository now includes a ready recipe skeleton at:

- `recipes/ineersa/agent-core/0.1/manifest.json`
- `recipes/ineersa/agent-core/0.1/config/**`
- `recipes/ineersa/agent-core/0.1/post-install.txt`

## What the recipe does

- registers bundle: `Ineersa\AgentCore\AgentLoopBundle`
- copies bundle config: `config/packages/agent_loop.yaml`
- copies default messenger override: `config/packages/agent_loop_messenger.yaml`
  - defaults to Doctrine persistent async queues
  - requires running messenger consumers
  - override to `sync://` for quick smoke tests without workers

### API routes (auto-registered, no recipe file needed)

Routes are registered automatically via the `routing.controller` tag on `RunApiController` when `agent_loop.api.enabled` is `true` (the default). No route YAML file is needed.

To disable the API routes:

```yaml
agent_loop:
  api:
    enabled: false
```

## Publishing the recipe

To make this automatic in consumer apps, publish this recipe via a Flex recipe endpoint:

1. **Public recipe**
   - submit to `symfony/recipes-contrib`.
2. **Private/org recipe**
   - host your own recipe repository/index and configure Flex endpoint in consuming apps.

Until published, users must apply config manually (as documented in `docs/local-dev-symfony-app-setup.md`).
