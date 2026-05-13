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

### API routes

The bundle does not ship HTTP controllers. Consuming applications should create their own controllers using the bundle's public contracts (`AgentRunnerInterface`, `RunReadService`, `RunStoreInterface`, `EventStoreInterface`, etc.).

See `docs/architecture.md` for the full list of available services.

### Outbox projectors

The bundle ships two built-in outbox projectors:

- **JSONL** (`JsonlOutboxProjectorWorker`) — persists events to JSONL run logs via Flysystem.
- **Mercure** (`MercureOutboxProjectorWorker`) — publishes events to Mercure hub for real-time streaming.

Both are enabled by default. To disable one or both:

```yaml
# config/packages/agent_loop.yaml
agent_loop:
    outbox:
        jsonl: false
        mercure: false
```

When disabled, the worker service is not registered at all — no autowiring, no Messenger handler, no tag.

To add a custom projector (e.g., SSE, WebSocket, Redis Pub/Sub), implement `OutboxProjectorInterface`. It will be auto-tagged and collected by `OutboxProjector`:

```php
use Ineersa\AgentCore\Contract\OutboxProjectorInterface;
use Ineersa\AgentCore\Domain\Event\OutboxSink;

final readonly class SseOutboxProjector implements OutboxProjectorInterface
{
    public function sink(): OutboxSink { return OutboxSink::from('sse'); }
    public function processBatch(int $batchSize = 100, int $retryDelaySeconds = 30): void
    {
        // claim from outbox, push to SSE stream
    }
}
```

## Publishing the recipe

To make this automatic in consumer apps, publish this recipe via a Flex recipe endpoint:

1. **Public recipe**
   - submit to `symfony/recipes-contrib`.
2. **Private/org recipe**
   - host your own recipe repository/index and configure Flex endpoint in consuming apps.

Until published, users must apply config manually (as documented in `docs/local-dev-symfony-app-setup.md`).
