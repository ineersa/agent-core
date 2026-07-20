<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Durable pending human-input request owned by RunState.
 *
 * Canonical waiting_human event payload remains the runtime/TUI surface.
 * This DTO is the AgentCore-owned reconstruction for answer validation and
 * continuation routing. Continuation references stay opaque to AgentCore.
 */
final readonly class PendingHumanInputRequestDTO
{
    /**
     * @param array<string, mixed>      $payload         canonical waiting_human / interrupt payload
     * @param array<string, mixed>|null $continuationRef reserved for future ToolCall continuation
     */
    public function __construct(
        #[SerializedName('question_id')]
        public string $questionId,
        public HumanInputContinuationKindEnum $continuationKind,
        /** @var array<string, mixed> */
        public array $payload,
        /** @var array<string, mixed>|null */
        #[SerializedName('continuation_ref')]
        public ?array $continuationRef = null,
    ) {
    }

    /**
     * Build a model-turn request from a canonical waiting_human / interrupt payload.
     *
     * Requires a non-empty question_id. Does not invent prompt/schema/identity fallbacks.
     *
     * @param array<string, mixed> $payload
     */
    public static function modelTurnFromInterruptPayload(array $payload): self
    {
        $questionId = $payload['question_id'] ?? null;
        if (!\is_string($questionId) || '' === $questionId) {
            throw new \InvalidArgumentException('waiting_human payload is missing non-empty question_id.');
        }

        return new self(
            questionId: $questionId,
            continuationKind: HumanInputContinuationKindEnum::ModelTurn,
            payload: $payload,
            continuationRef: null,
        );
    }
}
