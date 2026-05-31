<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Optional interface for tool call hooks that requested approval
 * and want to receive the human's answer.
 *
 * A hook implementing this interface will be called back when the
 * human answers a RequireApproval decision that originated from
 * this hook. The hook can update its internal state based on the
 * answer (e.g., "Allow once" vs "Always allow" vs "Deny").
 *
 * The base ToolCallHookInterface stays unchanged — hooks that do
 * not need answers simply do not implement this interface.
 *
 * @see ToolCallHookInterface
 * @see ApprovalAnswerContextDTO
 */
interface ApprovalAnswerHookInterface
{
    /**
     * Called when a human answers a RequireApproval decision that
     * originated from this hook.
     *
     * The context provides the question ID, the human's answer text,
     * the tool name, and the full approval context that was provided
     * when RequireApproval was returned.
     */
    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void;
}
