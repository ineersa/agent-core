# ArtifactStoreInterface

**File:** `ArtifactStoreInterface.php`  
**Namespace:** `Ineersa\AgentCore\Contract`

## Purpose

Named artifact storage for runs. Used to persist outputs like logs, files, or generated content.

## Methods

| Method | Signature | Description |
|--------|-----------|-------------|
| `put` | `put(string $runId, string $artifactName, string $content, array $metadata = []): string` | Store an artifact. Returns an identifier/URI. |

## Notes

- `metadata` is an arbitrary key-value map for content-type, tags, etc.
