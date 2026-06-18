<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

/**
 * Captures the exact model-facing content of generated/tool-originated
 * messages after all transform/convert hooks have been applied.
 *
 * This represents what the LLM provider actually receives — the canonical
 * "what the model sees" text. Populated by LlmPlatformAdapter after
 * applyConvertHooks() produces the final MessageBag, then carried through
 * the invocation result pipeline into LLM outcome events (completed,
 * failed, aborted) for projection into the TUI.
 *
 * Supports both tool-role messages (ToolCallMessage from provider input)
 * and generated user-role messages (synthetic image placeholders, etc.).
 * Normal user prompts are not captured.
 */
final readonly class ModelInputMessageDTO
{
    /**
     * @param array<string, mixed> $metadata Optional metadata (role, cap info, source hints)
     */
    public function __construct(
        public string $role,
        public string $text,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public string $source = '',
        public array $metadata = [],
    ) {
    }

    /**
     * @return array{role: string, text: string, tool_call_id: string|null, tool_name: string|null, source: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'tool_call_id' => $this->toolCallId,
            'tool_name' => $this->toolName,
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }
}
