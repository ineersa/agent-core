<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Approval;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;

/**
 * Interface for tool call hooks that requested approval via RequireApproval
 * and want to receive the human's answer and determine the tool-execution outcome.
 *
 * A hook implementing this interface will be called back when the
 * human answers a RequireApproval decision that originated from
 * this hook (via canonical answer_human resume of the exact tool call).
 * Two callbacks are invoked in order:
 *
 * 1. onApprovalAnswered() — optional side-effects (policy writes, metrics, etc.). Must not throw.
 * 2. resolveApprovalAnswer() — determines the tool-execution outcome
 *    (Allow, Block, or ReplaceResult) from the human's answer.
 *
 * The base ToolCallHookInterface stays unchanged — hooks that do
 * not need answers simply do not implement this interface.
 *
 * @see \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface
 * @see ToolCallDecisionDTO
 * @see \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionKindEnum
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
     *
     * This is called BEFORE resolveApprovalAnswer() and is intended
     * for optional side-effects (SafeGuard currently no-ops).
     * It must not return a decision — use resolveApprovalAnswer() for that.
     */
    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void;

    /**
     * Resolve the human's answer into a tool-execution decision.
     *
     * Called AFTER onApprovalAnswered(). The returned ToolCallDecisionDTO
     * is applied by the subscriber:
     *   - Allow: the real tool handler runs (answer means "permit").
     *   - Block: the tool call is denied with the given reason.
     *   - ReplaceResult: the supplied result replaces the tool output.
     *
     * The hook owns the complete answer-to-decision mapping, including
     * the answer vocabulary (e.g. "✅ Allow", "❌ Deny")
     * and the reasons/denied strings for blocked results.
     *
     * @param ApprovalAnswerContextDTO $context the human's answer + metadata
     *
     * @return ToolCallDecisionDTO Allow, Block, or ReplaceResult
     */
    public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO;
}
