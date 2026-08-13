<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Internal typed answer attached to an exact {@see \Ineersa\AgentCore\Domain\Message\ExecuteToolCall}
 * after human resolution of a ToolCall-continuation WaitingHuman request.
 *
 * Never model-visible tool arguments. Replay/persistence-safe.
 * Wire keys remain snake_case for historical tool-batch snapshot rows.
 */
final readonly class ToolCallHumanInputAnswerDTO
{
    /**
     * @param array<string, mixed> $continuationRef run_id/turn_no/step_id/tool_call_id
     * @param array<string, mixed> $requestPayload  original waiting_human payload (hook identity + approval_context)
     */
    public function __construct(
        #[SerializedName('question_id')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\NotBlank]
        public string $questionId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public mixed $answer,
        #[SerializedName('continuation_ref')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public array $continuationRef,
        #[SerializedName('request_payload')]
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public array $requestPayload,
    ) {
    }

    public function isEquivalent(self $other): bool
    {
        return $this->questionId === $other->questionId
            && $this->answer === $other->answer
            && $this->continuationRef === $other->continuationRef
            && $this->requestPayload === $other->requestPayload;
    }
}
