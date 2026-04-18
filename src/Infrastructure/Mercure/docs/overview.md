# Infrastructure\Mercure

## RunEventPublisher

Publishes `RunEvent` via Symfony's Mercure Hub for real-time streaming to clients. Serializes events to JSON and pushes to run-scoped topics (e.g., `runs/{runId}`).
