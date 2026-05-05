# Agentic loop core

## API routes (bundle consumers)

To enable HTTP API endpoints from this bundle in your Symfony app, import controller attributes:

```yaml
# config/routes/agent_loop.yaml
agent_loop_api:
  resource: '@AgentLoopBundle/Api/Http/'
  type: attribute
```

A ready-to-copy file is provided in this repository at `config/routes/agent_loop.yaml`.

## Security model

### Authorization extension point

The bundle ships a permissive default (`AllowAllAuthorizeRun` for `AuthorizeRunInterface`). Host applications should override the service alias for `AuthorizeRunInterface` to enforce tenant/user ownership, voters, ACL, or custom policy logic.

Authorization is an application-level concern — the bundle provides the contract, the consuming app implements enforcement in its own controllers.

## Architecture notes

Relationship maps live in nested AGENTS.md files (not in TOON indexes):

- `src/Application/AGENTS.md`
- `src/Domain/AGENTS.md`
- `src/Domain/Message/AGENTS.md`
- `src/Domain/Event/AGENTS.md`

Use these for command/event/message topology (`command -> handler`, `event -> projector/listener`, `message -> dispatched-by/handled-by`).

## Operations docs

- Dashboard spec: `docs/operations/agent-loop-observability-dashboard.md`
- Alert rules: `docs/operations/agent-loop-alert-rules.yaml`
- On-call recovery runbook: `docs/operations/agent-loop-oncall-runbook.md`

## Local development docs

- Integrate local bundle into a Symfony app: `docs/local-dev-symfony-app-setup.md`
- Helper script for link/copy/rollback into a target app: `./link`
- Flex recipe scaffold + publishing notes: `docs/flex-recipe.md`
