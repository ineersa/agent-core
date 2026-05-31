<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;

/**
 * SafeGuard tool-call hook that intercepts tool execution and applies
 * SafeGuard classification rules.
 *
 * Decision mapping:
 *   - Allow → Allow (tool executes normally)
 *   - HardBlock (sudo/su) → Block (never negotiable)
 *   - Relaxable categories (destructive, dangerous_git, sensitive_info,
 *     custom_dangerous, write_outside_cwd, protected_read):
 *       - autoDenyInNoninteractive=true → Block with auto_denied flag
 *       - autoDenyInNoninteractive=false → RequireApproval with approval schema
 *
 * Approval flow:
 *   1. onToolCall() returns RequireApproval with a unique question_id
 *   2. ExtensionToolHookEventSubscriber converts it to an interrupt payload
 *   3. Run pauses at WaitingHuman, TUI shows approval prompt
 *   4. Human answers (Allow once / Always allow / Deny)
 *   5. Answer persisted to events.jsonl by ApplyCommandHandler
 *   6. LLM retries the tool call (same tool worker process)
 *   7. onToolCall() checks ApprovalSessionTracker for pending → resolves answer
 *      from events.jsonl via SessionEventReader
 *   8. "Allow once" → approve in tracker → Allow (one-time, consumed on this call)
 *      "Always allow" → approve in tracker + persist to policy file → Allow
 *      "Deny" → remove from tracker → Block
 */
final readonly class SafeGuardToolCallHook implements ToolCallHookInterface
{
    private const array APPROVAL_SCHEMA = [
        'type' => 'string',
        'enum' => ['Allow once', 'Always allow', 'Deny'],
    ];

    /**
     * Categories that can be promoted from Block to RequireApproval.
     */
    private const array RELAXABLE_CATEGORIES = [
        SafeGuardDecisionKind::Destructive,
        SafeGuardDecisionKind::DangerousGit,
        SafeGuardDecisionKind::SensitiveInfo,
        SafeGuardDecisionKind::CustomDangerous,
        SafeGuardDecisionKind::WriteOutsideCwd,
        SafeGuardDecisionKind::ProtectedRead,
    ];

    public function __construct(
        private SafeGuardClassifier $classifier,
        private SafeGuardPolicy $policy,
        private ApprovalSessionTracker $tracker,
        private ?SafeGuardPolicyWriter $policyWriter,
        private bool $autoDenyInNoninteractive,
        private string $cwd,
    ) {
    }

    public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
    {
        // 1. Check if already approved from a previous answer
        $key = $this->resolveOperationKey($context);

        if (null !== $key && $this->tracker->isApproved($key)) {
            $this->tracker->consumeApproval($key);

            return ToolCallDecisionDTO::allow();
        }

        // 2. Check if pending approval has been answered
        if (null !== $key && $this->tracker->hasPending($key)) {
            $answer = $this->tracker->resolveAnswer($key);

            if (null !== $answer) {
                return $this->handleAnswer($key, $answer, $context);
            }

            // Still pending — the answer is not in events.jsonl yet.
            // Block and let the LLM retry when the answer arrives.
            return ToolCallDecisionDTO::block(
                reason: 'Approval still pending — answer not found in event store.',
                details: [
                    'category' => 'approval_pending',
                    'intercepted' => true,
                    'denied' => true,
                ],
            );
        }

        // 3. Classify the tool call
        $decision = $this->classifier->classify(
            toolName: $context->toolName,
            arguments: $context->arguments,
            cwd: $this->cwd,
            policy: $this->policy,
        );

        // 4. Allow → Allow
        if ($decision->isAllowed()) {
            return ToolCallDecisionDTO::allow();
        }

        // 5. HardBlock → Block (never negotiable)
        if (SafeGuardDecisionKind::HardBlock === $decision->kind) {
            return ToolCallDecisionDTO::block(
                reason: $decision->reason,
                details: [
                    'category' => $decision->kind->value,
                    'intercepted' => true,
                    'denied' => true,
                ],
            );
        }

        // 6. Relaxable categories
        if ($this->isRelaxable($decision->kind)) {
            if ($this->autoDenyInNoninteractive) {
                return ToolCallDecisionDTO::block(
                    reason: $decision->reason,
                    details: [
                        'category' => $decision->kind->value,
                        'intercepted' => true,
                        'denied' => true,
                        'auto_denied' => true,
                    ],
                );
            }

            $questionId = $this->generateQuestionId($context, $decision);
            $sessionId = $context->runId ?? '';
            $command = $this->extractCommand($context);

            if (null !== $key) {
                $this->tracker->markPending($key, $questionId, $sessionId);
            }

            return ToolCallDecisionDTO::requireApproval(
                prompt: $this->buildPrompt($decision, $command),
                questionId: $questionId,
                schema: self::APPROVAL_SCHEMA,
                details: [
                    'category' => $decision->kind->value,
                    'tool_name' => $context->toolName,
                    'command' => $command,
                    'approval_context' => [
                        'category' => $decision->kind->value,
                        'command' => $command,
                    ],
                ],
            );
        }

        // 7. Default: Block for any unhandled category
        return ToolCallDecisionDTO::block(
            reason: $decision->reason,
            details: [
                'category' => $decision->kind->value,
                'intercepted' => true,
                'denied' => true,
            ],
        );
    }

    /**
     * Handle a resolved answer from the approval flow.
     */
    private function handleAnswer(string $key, string $answer, ToolCallContextDTO $context): ToolCallDecisionDTO
    {
        if ('Deny' === $answer) {
            $this->tracker->remove($key);

            return ToolCallDecisionDTO::block(
                reason: 'User denied approval.',
                details: [
                    'denied_by_user' => true,
                    'intercepted' => true,
                    'denied' => true,
                ],
            );
        }

        if ('Always allow' === $answer && null !== $this->policyWriter) {
            // Re-classify to get the category and command for persistence
            $decision = $this->classifier->classify(
                toolName: $context->toolName,
                arguments: $context->arguments,
                cwd: $this->cwd,
                policy: $this->policy,
            );
            $command = $this->extractCommand($context);
            $this->policyWriter->addAllowPattern($decision->kind->value, $command);

            return ToolCallDecisionDTO::allow();
        }

        // "Allow once" (default affirmative) — approval was already consumed
        // by resolveAnswer. This call just advances to Allow.
        return ToolCallDecisionDTO::allow();
    }

    private function isRelaxable(SafeGuardDecisionKind $kind): bool
    {
        return \in_array($kind, self::RELAXABLE_CATEGORIES, true);
    }

    private function resolveOperationKey(ToolCallContextDTO $context): ?string
    {
        $command = $this->extractCommand($context);

        if ('' !== $command) {
            return $context->toolName.':'.$command;
        }

        $path = $this->extractPath($context);

        if ('' !== $path) {
            return $context->toolName.':'.$path;
        }

        return null;
    }

    private function extractCommand(ToolCallContextDTO $context): string
    {
        $command = $context->arguments['command'] ?? null;

        return \is_string($command) ? $command : '';
    }

    private function extractPath(ToolCallContextDTO $context): string
    {
        $path = $context->arguments['path'] ?? null;

        return \is_string($path) ? $path : '';
    }

    private function generateQuestionId(ToolCallContextDTO $context, Policy\SafeGuardDecision $decision): string
    {
        return hash('sha256', \sprintf(
            'safeguard|%s|%s|%s',
            $context->toolName,
            $decision->kind->value,
            (string) microtime(true),
        ));
    }

    private function buildPrompt(Policy\SafeGuardDecision $decision, string $command): string
    {
        return \sprintf(
            "SafeGuard: %s\n\nOperation: %s\nTool: %s\n\nAllow this operation?",
            $decision->reason,
            '' !== $command ? $command : '(path-based)',
            $decision->toolName,
        );
    }
}
