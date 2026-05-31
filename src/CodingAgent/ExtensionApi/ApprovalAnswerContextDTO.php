<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Context provided to ApprovalAnswerHookInterface::onApprovalAnswered().
 *
 * Contains the question ID, the human's answer text, the tool name
 * that was blocked, and the full approval context from the original
 * RequireApproval decision.
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
    ) {
    }
}
