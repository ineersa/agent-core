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
 *
 * Wire/persisted shape uses snake_case optional tool fields and omits null
 * optionals (see {@see toArray()}). Decode once at trust/array boundaries via
 * {@see fromArray()} / {@see listFromMixed()}.
 *
 * DTOs decoded from an array retain that original wire row privately so
 * {@see toArray()} re-emits it unchanged (unknown keys, key order, missing
 * optionals, and invalid optional types). Direct producer construction still
 * emits the canonical historical shape. Raw-sensitive predicates
 * ({@see isToolResultReplace()}, {@see hasNonEmptyId()}, {@see hasNonEmptyText()})
 * use original wire types when present; projection soft-casts stay on the
 * public typed fields.
 */
final readonly class ModelNotificationDTO
{
    /**
     * @param string                    $id              deterministic dedup/replay identifier
     * @param string                    $source          Producer identity: output_cap, safeguard, extension, system, …
     * @param string                    $kind            Sub-type within the source: output_capped, tool_blocked, …
     * @param string                    $severity        info | warning | error (drives TUI icon/theme color)
     * @param string                    $delivery        how the notification reaches the model:
     *                                                   tool_result_replace — replaces tool-result content;
     *                                                   context_message — free-standing user/system message
     * @param string                    $text            Exact text the model receives.  Must be a single
     *                                                   non-empty string (no parse-then-reconstruct).
     * @param string|null               $toolCallId      related tool call, when delivery= tool_result_replace
     * @param string|null               $toolName        name of the related tool
     * @param int|null                  $orderIndex      tool call order index from the assistant message
     * @param array<string, mixed>      $metadata        Arbitrary producer/consumer payload
     *                                                   (cap limit, char count, saved path, policy ref, etc.).
     * @param array<string, mixed>|null $originalPayload retained wire row when decoded via {@see fromArray()}
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
        private ?array $originalPayload = null,
    ) {
    }

    /**
     * Serialize to the historical event / details wire shape.
     *
     * When this DTO was decoded from an array, returns that original row
     * unchanged. Direct producer construction emits the canonical shape:
     * optional tool_* / order_index keys are omitted when null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (null !== $this->originalPayload) {
            return $this->originalPayload;
        }

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

    /**
     * Decode one historical notification row with the same soft defaults used
     * by pre-typed projection consumers (empty strings, severity default info).
     * Retains the input array for exact re-emit via {@see toArray()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $toolCallId = $data['tool_call_id'] ?? null;
        $toolName = $data['tool_name'] ?? null;
        $orderIndex = $data['order_index'] ?? null;
        $metadata = $data['metadata'] ?? [];

        // Match historical projection casts: (string) with severity default 'info',
        // optional tool fields only when string/int respectively.
        return new self(
            id: (string) ($data['id'] ?? ''),
            source: (string) ($data['source'] ?? ''),
            kind: (string) ($data['kind'] ?? ''),
            severity: (string) ($data['severity'] ?? 'info'),
            delivery: (string) ($data['delivery'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            toolCallId: \is_string($toolCallId) ? $toolCallId : null,
            toolName: \is_string($toolName) ? $toolName : null,
            orderIndex: \is_int($orderIndex) ? $orderIndex : null,
            metadata: \is_array($metadata) ? $metadata : [],
            originalPayload: $data,
        );
    }

    /**
     * Soft-decode a mixed list/map; non-array rows are skipped.
     *
     * @param list<mixed>|array<int|string, mixed>|null $rows
     *
     * @return list<self>
     */
    public static function listFromMixed(?array $rows): array
    {
        if (null === $rows || [] === $rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            /* @var array<string, mixed> $row */
            $out[] = self::fromArray($row);
        }

        return $out;
    }

    /**
     * Raw-sensitive: original wire delivery must be exact string
     * 'tool_result_replace' (not soft-cast). Direct producer DTOs use typed field.
     */
    public function isToolResultReplace(): bool
    {
        if (null !== $this->originalPayload) {
            return ($this->originalPayload['delivery'] ?? null) === 'tool_result_replace';
        }

        return 'tool_result_replace' === $this->delivery;
    }

    /**
     * Raw-sensitive: original wire id must be a non-empty string. Direct
     * producer DTOs use the typed field.
     */
    public function hasNonEmptyId(): bool
    {
        if (null !== $this->originalPayload) {
            $id = $this->originalPayload['id'] ?? null;

            return \is_string($id) && '' !== $id;
        }

        return '' !== $this->id;
    }

    /**
     * Raw-sensitive: original wire text must be a non-empty string. Direct
     * producer DTOs use the typed field.
     */
    public function hasNonEmptyText(): bool
    {
        if (null !== $this->originalPayload) {
            $text = $this->originalPayload['text'] ?? null;

            return \is_string($text) && '' !== $text;
        }

        return '' !== $this->text;
    }
}
