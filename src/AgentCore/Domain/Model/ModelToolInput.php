<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Captures the exact model-facing content of a tool result message
 * after all transform hooks have been applied.
 *
 * This represents what the LLM provider actually receives as the
 * tool-role message content — the canonical "what the model sees" text.
 *
 * Populated by LlmPlatformAdapter after applyConvertHooks() produces
 * the final MessageBag, then carried through the invocation result
 * pipeline into llm_step_completed events for projection into the TUI.
 */
final readonly class ModelToolInput
{
    /**
     * @param array<string, mixed> $metadata Optional metadata (cap info, source hints)
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public string $text,
        public array $metadata = [],
    ) {
    }

    /**
     * @return array{tool_call_id: string, tool_name: string, text: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'text' => $this->text,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array{tool_call_id: string, tool_name: string, text: string, metadata?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            toolCallId: (string) ($data['tool_call_id'] ?? ''),
            toolName: (string) ($data['tool_name'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
