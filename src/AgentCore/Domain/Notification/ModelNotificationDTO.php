<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Notification;

/**
 * Generic model-facing notification carrying exact provider-bound text
 * and structured source/kind/severity/delivery metadata.
 *
 * Producers (OutputCap, SafeGuard, extensions, internal guidance) create
 * instances that flow through tool-result envelopes, canonical agent-message
 * history, and model_notification events. Consumers (TUI projection, audit
 * log, model history) render the exact text without text parsing or
 * heuristics.
 *
 * Every notification has a deterministic {@see $id} for dedup and replay.
 * Wire/persisted shape uses snake_case optional tool fields; null optionals
 * are omitted by Serializer SKIP_NULL_VALUES at array boundaries.
 */
final readonly class ModelNotificationDTO
{
    /**
     * @param array<string, mixed> $metadata Arbitrary producer/consumer payload
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $kind,
        public string $severity,
        public string $delivery,
        public string $text,
        public array $metadata = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?int $orderIndex = null,
    ) {
    }
}
