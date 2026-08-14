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
        $this->assertNonBlank($id, 'id');
        $this->assertNonBlank($source, 'source');
        $this->assertNonBlank($kind, 'kind');
        $this->assertNonBlank($severity, 'severity');
        $this->assertNonBlank($delivery, 'delivery');
        // Preserve exact model-facing text; reject blank without trimming/rebuilding.
        if ('' === $text) {
            throw new \InvalidArgumentException('ModelNotificationDTO.text must be nonblank.');
        }

        if (null !== $toolCallId && '' === trim($toolCallId)) {
            throw new \InvalidArgumentException('ModelNotificationDTO.toolCallId must be nonblank when provided.');
        }
        if (null !== $toolName && '' === trim($toolName)) {
            throw new \InvalidArgumentException('ModelNotificationDTO.toolName must be nonblank when provided.');
        }
        if ('tool_result_replace' === $delivery && (null === $toolCallId || '' === trim($toolCallId))) {
            throw new \InvalidArgumentException('ModelNotificationDTO.toolCallId is required and nonblank when delivery is tool_result_replace.');
        }
    }

    private function assertNonBlank(string $value, string $field): void
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('ModelNotificationDTO.%s must be nonblank.', $field));
        }
    }
}
