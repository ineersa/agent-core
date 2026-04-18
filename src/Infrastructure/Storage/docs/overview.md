# Infrastructure\Storage

Storage adapters implementing Contract interfaces.

## In-Memory Stores (testing)

| Store | Interface | Notes |
|-------|-----------|-------|
| `InMemoryRunStore` | `RunStoreInterface` | Map-based get/CAS |
| `InMemoryCommandStore` | `CommandStoreInterface` | Array-backed queue |
| `InMemoryOutboxStore` | `OutboxStoreInterface` | Array-backed with claim logic |
| `InMemoryPromptStateStore` | `PromptStateStoreInterface` | Map-based get/save/delete |

## Doctrine Stores

| Store | Interface | Notes |
|-------|-----------|-------|
| `RunEventStore` | `EventStoreInterface` | DBAL-backed append/appendMany/allFor |
| `HotPromptStateStore` | `PromptStateStoreInterface` | Doctrine-backed hot prompt snapshots |

## Flysystem Stores

| Store | Interface | Notes |
|-------|-----------|-------|
| `RunLogWriter` | — | JSONL append via Flysystem |
| `RunLogReader` | — | JSONL parse back to RunEvent |
| `LocalArtifactStore` | `ArtifactStoreInterface` | Filesystem-based artifact storage |
