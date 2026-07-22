<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Internal typed answer attached to an exact {@see ExecuteToolCall} after human
 * resolution of a ToolCall-continuation WaitingHuman request.
 *
 * Never model-visible tool arguments. Replay/persistence-safe.
 */
final readonly class ToolCallHumanInputAnswerDTO
{
    /**
     * @param array<string, mixed> $continuationRef run_id/turn_no/step_id/tool_call_id
     * @param array<string, mixed> $requestPayload  original waiting_human payload (hook identity + approval_context)
     */
    public function __construct(
        public string $questionId,
        public mixed $answer,
        public array $continuationRef,
        public array $requestPayload,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromPersistedArray(array $data): self
    {
        $questionId = $data['question_id'] ?? null;
        if (!\is_string($questionId) || '' === $questionId) {
            throw new \UnexpectedValueException('ToolCallHumanInputAnswerDTO.question_id must be a non-empty string.');
        }

        $continuationRef = $data['continuation_ref'] ?? null;
        if (!\is_array($continuationRef)) {
            throw new \UnexpectedValueException('ToolCallHumanInputAnswerDTO.continuation_ref must be an array.');
        }

        $requestPayload = $data['request_payload'] ?? null;
        if (!\is_array($requestPayload)) {
            throw new \UnexpectedValueException('ToolCallHumanInputAnswerDTO.request_payload must be an array.');
        }

        if (!\array_key_exists('answer', $data)) {
            throw new \UnexpectedValueException('ToolCallHumanInputAnswerDTO.answer is required.');
        }

        return new self($questionId, $data['answer'], $continuationRef, $requestPayload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistedArray(): array
    {
        return [
            'question_id' => $this->questionId,
            'answer' => $this->answer,
            'continuation_ref' => $this->continuationRef,
            'request_payload' => $this->requestPayload,
        ];
    }

    public function isEquivalent(self $other): bool
    {
        return $this->questionId === $other->questionId
            && $this->answer === $other->answer
            && $this->continuationRef === $other->continuationRef
            && $this->requestPayload === $other->requestPayload;
    }
}
