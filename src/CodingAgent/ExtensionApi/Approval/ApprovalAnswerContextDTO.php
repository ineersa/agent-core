<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Approval;

/**
 * Context provided to ApprovalAnswerHookInterface::onApprovalAnswered()
 * and resolveApprovalAnswer().
 *
 * Contains the question ID, the human's answer text, the tool name
 * that was blocked, the full approval context from the original
 * RequireApproval decision, and the run/tool-call identifiers so
 * extensions do not need to re-stash these in details.
 *
 * This DTO uses only PHP-native types — no Symfony, AgentCore, or
 * CodingAgent dependencies. It is part of the public ExtensionApi
 * compatibility surface.
 *
 * @see ApprovalAnswerHookInterface
 * @see ToolCallDecisionDTO::requireApproval()
 */
final readonly class ApprovalAnswerContextDTO
{
    /**
     * @param array<string, mixed> $approvalContext the details from the original RequireApproval decision
     */
    public function __construct(
        public string $questionId,
        public string $answer,
        public string $toolName,
        public array $approvalContext,
        public ?string $runId = null,
        public ?string $toolCallId = null,
    ) {
    }
}
