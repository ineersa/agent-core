<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Durable pending human-input request owned by RunState.
 *
 * Canonical waiting_human event payload remains the runtime/TUI surface.
 * This DTO is the AgentCore-owned reconstruction of that request for answer
 * validation and continuation routing. Continuation references stay opaque
 * to AgentCore (no CodingAgent/extension types).
 */
final readonly class PendingHumanInputRequestDTO
{
    /**
     * @param array<string, mixed>      $schema
     * @param array<string, mixed>|null $continuationRef opaque correlation for future ToolCall continuation
     * @param array<string, mixed>      $displayPayload  extra interrupt keys preserved for replay/display
     */
    public function __construct(
        #[SerializedName('question_id')]
        public string $questionId,
        public string $prompt,
        /** @var array<string, mixed> */
        public array $schema,
        public HumanInputContinuationKindEnum $continuationKind,
        #[SerializedName('tool_call_id')]
        public ?string $toolCallId = null,
        #[SerializedName('tool_name')]
        public ?string $toolName = null,
        /** @var array<string, mixed>|null */
        #[SerializedName('continuation_ref')]
        public ?array $continuationRef = null,
        /** @var array<string, mixed> */
        #[SerializedName('display_payload')]
        public array $displayPayload = [],
    ) {
    }

    /**
     * Build a model-turn request from a canonical waiting_human / interrupt payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function modelTurnFromInterruptPayload(array $payload): self
    {
        $questionId = \is_string($payload['question_id'] ?? null) && '' !== $payload['question_id']
            ? $payload['question_id']
            : (string) ($payload['tool_call_id'] ?? '');

        if ('' === $questionId) {
            throw new \InvalidArgumentException('waiting_human payload is missing question_id/tool_call_id.');
        }

        $prompt = \is_string($payload['prompt'] ?? null) && '' !== $payload['prompt']
            ? $payload['prompt']
            : 'Human input required.';

        $schema = \is_array($payload['schema'] ?? null)
            ? $payload['schema']
            : ['type' => 'string'];

        $toolCallId = \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null;
        $toolName = \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null;

        $known = ['question_id', 'prompt', 'schema', 'tool_call_id', 'tool_name', 'kind', 'continuation_kind', 'continuation_ref'];
        $displayPayload = [];
        foreach ($payload as $key => $value) {
            if (!\is_string($key) || \in_array($key, $known, true)) {
                continue;
            }
            $displayPayload[$key] = $value;
        }

        return new self(
            questionId: $questionId,
            prompt: $prompt,
            schema: $schema,
            continuationKind: HumanInputContinuationKindEnum::ModelTurn,
            toolCallId: $toolCallId,
            toolName: $toolName,
            continuationRef: null,
            displayPayload: $displayPayload,
        );
    }
}
