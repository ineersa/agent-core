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
 *
 * Payload invariant: `$payload` is the complete waiting_human / interrupt
 * map stored as-is (no field extraction or backfill). It must include a
 * non-empty `question_id`; prompt/schema/tool identity fields are optional
 * and are read from `$payload` when present rather than mirrored as DTO
 * properties. `continuationRef` is null for ModelTurn and holds opaque
 * run/turn/step/tool_call correlation for ToolCall continuation.
 */
final readonly class PendingHumanInputRequestDTO
{
    /**
     * @param array<string, mixed>      $payload         complete waiting_human / interrupt map (includes question_id)
     * @param array<string, mixed>|null $continuationRef ToolCall correlation (run_id/turn_no/step_id/tool_call_id); null for ModelTurn
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
     * Requires a non-empty question_id. Stores `$payload` unchanged; does not
     * invent prompt/schema/identity fallbacks or split fields out of the map.
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $continuationRef
     */
    public static function toolCallFromPayload(array $payload, array $continuationRef): self
    {
        $questionId = $payload['question_id'] ?? null;
        if (!\is_string($questionId) || '' === $questionId) {
            throw new \InvalidArgumentException('waiting_human payload is missing non-empty question_id.');
        }

        // Ensure event/payload surface is self-describing for replay reconstruction.
        $payload['continuation_kind'] = HumanInputContinuationKindEnum::ToolCall->value;
        $payload['continuation_ref'] = $continuationRef;

        return new self(
            questionId: $questionId,
            continuationKind: HumanInputContinuationKindEnum::ToolCall,
            payload: $payload,
            continuationRef: $continuationRef,
        );
    }
}
