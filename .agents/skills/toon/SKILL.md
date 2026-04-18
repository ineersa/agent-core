---
name: toon
description: TOON (Token-Oriented Object Notation) format for writing ai-index files. Use when creating or updating ai-index.toon files.
---

# TOON Format for AI Indexes

TOON is a compact, human-readable data format optimized for LLM token efficiency. All `ai-index` files use TOON instead of JSON.

## Quick reference

- **Key-value pairs**: `key: value` (no quotes needed for simple strings)
- **Objects**: indented key-value pairs under a parent key
- **Tabular arrays**: `key[N]{col1,col2,...}:` header + N data rows with comma-separated values
- **String values**: quote only if they contain colons, commas, newlines, or start with `[`
- **Comments**: lines starting with `#`

## Scripting escape hatch

For partial updates or complex transformations, use a disposable PHP script:

```php
<?php
require 'vendor/autoload.php';
use HelgeSverre\Toon\Toon;

// Read existing index
$data = Toon::decode(file_get_contents('src/Domain/ai-index.toon'));

// Modify
$data['files'][] = [
    'file' => 'NewFile.php',
    'type' => 'class',
    'responsibility' => 'Short description',
    'docs' => 'docs/NewFile.md',
];

// Write back
file_put_contents('src/Domain/ai-index.toon', Toon::encode($data));
```

Run: `php -f /tmp/update-index.php`

This is simpler and more reliable than hand-writing TOON for partial updates.

## Index schemas in TOON

### Root index (`ai-index.toon`)

```
spec: agent-core.ai-docs/v1
package: ineersa/agent-core
updatedAt: 2026-04-18
description: Agent loop engine — event-sourced run lifecycle LLM orchestration tool execution and hook system for Symfony
rootFile: src/AgentLoopBundle.php
config:
  services: config/services.php
  messenger: config/messenger.php
  doctrine: config/doctrine.php
namespaces[7]{namespace,fqcn,path,description,index}:
  DependencyInjection,Ineersa\AgentCore\DependencyInjection,src/DependencyInjection,Bundle extension loading config validation framework config prepend,src/DependencyInjection/ai-index.toon
  Contract,Ineersa\AgentCore\Contract,src/Contract,Stable interfaces for runner API storage abstractions tools hooks and extensions,src/Contract/ai-index.toon
  ...
```

### Namespace index with files (`src/<NS>/ai-index.toon`)

```
spec: agent-core.ai-docs/v1
namespace: Event
fqcn: Ineersa\AgentCore\Domain\Event
description: Event sourcing models base RunEvent outbox pattern Entry/Sink boundary hooks core lifecycle event types
files[6]{file,type,responsibility,docs}:
  RunEvent.php,readonly class,"Base event — runId seq turnNo type payload createdAt",docs/RunEvent.md
  OutboxEntry.php,readonly class,Outbox pattern entry — id sink event attempts availableAt,docs/OutboxEntry.md
  OutboxSink.php,enum,Outbox sink targets: Jsonl Mercure,docs/OutboxSink.md
  BoundaryHookEvent.php,final class,Boundary hook invocation event — hookName + mutable context array,docs/BoundaryHookEvent.md
  BoundaryHookName.php,final class,Boundary hook name constants BEFORE/AFTER_COMMAND_APPLY etc,docs/BoundaryHookName.md
  CoreLifecycleEventType.php,final class,Lifecycle event type constants class map and validateOrder,docs/CoreLifecycleEventType.md
subNamespaces[1]{namespace,fqcn,path,description,index}:
  Lifecycle,Ineersa\AgentCore\Domain\Event\Lifecycle,Lifecycle,Typed lifecycle event subclasses,Lifecycle/ai-index.toon
```

### Namespace index with only subNamespaces (no files)

```
spec: agent-core.ai-docs/v1
namespace: Domain
fqcn: Ineersa\AgentCore\Domain
description: Framework-agnostic core models run state commands events message envelopes tool DTOs
subNamespaces[5]{namespace,fqcn,path,description,index}:
  Run,Ineersa\AgentCore\Domain\Run,Run,Run lifecycle value objects RunId RunHandle RunState RunStatus,Run/ai-index.toon
  Command,Ineersa\AgentCore\Domain\Command,Command,Command value objects CoreCommandKind PendingCommand RoutedCommand,Command/ai-index.toon
  ...
```

## Key rules

1. **Tabular arrays** use the `key[N]{headers}:` format for uniform objects
2. **Commas in values** must be quoted (e.g., `"value with, comma"`)
3. **Descriptions** should be compressed: remove articles, use noun phrases, drop "and"
4. **All index references** use `.toon` extension (not `.json`)
5. **updatedAt** always reflects the current date
