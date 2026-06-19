<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Notification;

/**
 * Generic model-facing notification carrying exact provider-bound text
 * and structured source/kind/severity/delivery metadata.
 *
 * Producers (OutputCap, SafeGuard, extensions, internal guidance) create
 * instances that flow through tool-result envelopes, canonical agent-message
 * history, and model_notification events.  Consumers (TUI projection, audit
 * log, model history) render the exact text without text parsing or
 * heuristics.
 *
 * Every notification has a deterministic {@see id} for dedup and replay.
 * The same id appears in the canonical AgentMessage history and in
 * model_notification RunEvents so downstream consumers can correlate them.
 */
final readonly class ModelNotificationDTO
{
    /**
     * @param string               $id         deterministic dedup/replay identifier
     * @param string               $source     Producer identity: output_cap, safeguard, extension, system, …
     * @param string               $kind       Sub-type within the source: output_capped, tool_blocked, …
     * @param string               $severity   info | warning | error (drives TUI icon/theme color)
     * @param string               $delivery   how the notification reaches the model:
     *                                         tool_result_replace — replaces tool-result content;
     *                                         context_message — free-standing user/system message
     * @param string               $text       Exact text the model receives.  Must be a single
     *                                         non-empty string (no parse-then-reconstruct).
     * @param string|null          $toolCallId related tool call, when delivery= tool_result_replace
     * @param string|null          $toolName   name of the related tool
     * @param int|null             $orderIndex tool call order index from the assistant message
     * @param array<string, mixed> $metadata   Arbitrary producer/consumer payload
     *                                         (cap limit, char count, saved path, policy ref, etc.).
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $kind,
        public string $severity,
        public string $delivery,
        public string $text,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?int $orderIndex = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Serialize to a plain array for storage in events / details.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'source' => $this->source,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'delivery' => $this->delivery,
            'text' => $this->text,
            'metadata' => $this->metadata,
        ];

        if (null !== $this->toolCallId) {
            $payload['tool_call_id'] = $this->toolCallId;
        }

        if (null !== $this->toolName) {
            $payload['tool_name'] = $this->toolName;
        }

        if (null !== $this->orderIndex) {
            $payload['order_index'] = $this->orderIndex;
        }

        return $payload;
    }
}
