<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;

/**
 * SafeGuard tool-call hook that intercepts tool execution and applies
 * SafeGuard classification rules.
 *
 * Routes by tool name to SafeGuardClassifier, then maps the resulting
 * SafeGuardDecision to a ToolCallDecisionDTO for the Extension API.
 *
 * For policy-relaxable categories (Destructive, DangerousGit,
 * SensitiveInfo, CustomDangerous, WriteOutsideCwd, ProtectedRead),
 * returns RequireApproval to trigger the HITL approval flow instead
 * of immediately blocking.
 *
 * Implements ApprovalAnswerHookInterface to receive the human's answer
 * and update the ApprovalSessionTracker accordingly:
 * - "Allow once" → mark approved for the next retry
 * - "Always allow" → mark approved AND persist to policy file
 * - "Deny" → remove pending entry, command stays blocked
 */
final readonly class SafeGuardToolCallHook implements ToolCallHookInterface, ApprovalAnswerHookInterface
{
    /**
     * @var list<SafeGuardDecisionKind> Policy-relaxable categories that can be approved via HITL
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
        private ApprovalSessionTracker $approvalTracker,
        private ?SafeGuardPolicyWriter $policyWriter,
        private string $cwd,
        private bool $autoDenyInNoninteractive = true,
    ) {
    }

    public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
    {
        // Determine the operation key before classification so we can
        // check the approval tracker for a previously approved operation.
        $operationKey = $this->resolveOperationKey($context);

        // Check in-memory approval tracker first.
        // If the operation was just approved, consume the approval and allow.
        if (null !== $operationKey && $this->approvalTracker->consumeApproval($operationKey)) {
            return ToolCallDecisionDTO::allow();
        }

        $decision = $this->classifier->classify(
            toolName: $context->toolName,
            arguments: $context->arguments,
            cwd: $this->cwd,
            policy: $this->policy,
        );

        if ($decision->isAllowed()) {
            return ToolCallDecisionDTO::allow();
        }

        // HardBlock decisions (sudo, su) are never approvable.
        if (SafeGuardDecisionKind::HardBlock === $decision->kind) {
            return $this->block($decision);
        }

        // Policy-relaxable categories → RequireApproval (unless auto-deny is active
        // and no approval channel is available).
        //
        // An approval channel is signalled by HATFIELD_APPROVAL_CHANNEL env var:
        // - Interactive TUI sets this when spawning the controller process so
        //   messenger consumers inherit it. SafeGuard MUST prompt — the TUI
        //   can display the question and relay answers.
        // - Headless/worker contexts without the env var auto-block (fail-closed),
        //   unless the operator has explicitly set auto_deny_in_noninteractive=false.
        if ($this->isRelaxable($decision->kind)) {
            if ($this->autoDenyInNoninteractive && !$this->hasApprovalChannel()) {
                return ToolCallDecisionDTO::block(
                    reason: $decision->reason,
                    details: [
                        'category' => $decision->kind->value,
                        'intercepted' => true,
                        'denied' => true,
                        'auto_denied' => true,
                        'message' => \sprintf(
                            'Tool "%s" was blocked by SafeGuard: %s (noninteractive mode)',
                            $decision->toolName,
                            $decision->reason,
                        ),
                    ],
                );
            }

            // Determine the operation spec for the approval context
            $spec = $this->resolveOperationSpec($context);

            $questionId = \sprintf(
                'sg_%s',
                hash('sha256', \sprintf('%s|%s|%d', $operationKey ?? $spec, $context->toolCallId, hrtime(true))),
            );

            // Mark as pending in the tracker so onApprovalAnswered can approve it
            if (null !== $operationKey) {
                $this->approvalTracker->markPending($questionId, $operationKey);
            }

            return ToolCallDecisionDTO::requireApproval(
                prompt: \sprintf('Allow %s: %s?', $this->friendlyCategory($decision->kind), $decision->reason),
                questionId: $questionId,
                schema: [
                    'type' => 'string',
                    'enum' => ['Allow once', 'Always allow', 'Deny'],
                ],
                details: [
                    'category' => $decision->kind->value,
                    'command' => $this->extractCommand($context),
                    'path' => $this->extractPath($context),
                    'tool_name' => $decision->toolName,
                    'operation_key' => $operationKey,
                    'intercepted' => true,
                ],
            );
        }

        // Fallback: block everything else
        return $this->block($decision);
    }

    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
    {
        $operationKey = $context->approvalContext['operation_key'] ?? '';

        if ('' === $operationKey || !\is_string($operationKey)) {
            return;
        }

        if ('Deny' === $context->answer) {
            $this->approvalTracker->remove($operationKey);

            return;
        }

        if ('Allow once' === $context->answer) {
            $this->approvalTracker->approve($operationKey);

            return;
        }

        if ('Always allow' === $context->answer) {
            $this->approvalTracker->approve($operationKey);

            // Persist to policy file for future sessions
            $category = (string) ($context->approvalContext['category'] ?? '');
            $pattern = $this->resolvePatternFromAnswer($category, $context);

            if (null !== $pattern && null !== $this->policyWriter) {
                $this->policyWriter->addAllowPattern($category, $pattern);
            }

            return;
        }
    }

    /**
     * Determine the tracker operation key from the tool call context.
     *
     * Format: {category}:{normalized_command_or_path}
     * null if the operation cannot be identified uniquely.
     */
    private function resolveOperationKey(ToolCallContextDTO $context): ?string
    {
        $command = $this->extractCommand($context);
        if (null !== $command) {
            return \sprintf('%s:%s', $this->toolPrefix($context->toolName), $command);
        }

        $path = $this->extractPath($context);
        if (null !== $path) {
            return \sprintf('%s:%s', $this->toolPrefix($context->toolName), $path);
        }

        return null;
    }

    /**
     * Get a human-readable operation spec for the approval context.
     */
    private function resolveOperationSpec(ToolCallContextDTO $context): string
    {
        return $this->extractCommand($context) ?? $this->extractPath($context) ?? $context->toolName;
    }

    /**
     * Extract the command string from arguments (bash tool).
     *
     * @return string|null null if no command found
     */
    private function extractCommand(ToolCallContextDTO $context): ?string
    {
        $command = $context->arguments['command'] ?? null;

        return \is_string($command) && '' !== $command ? $command : null;
    }

    /**
     * Extract the path string from arguments (write/edit/read tools).
     *
     * @return string|null null if no path found
     */
    private function extractPath(ToolCallContextDTO $context): ?string
    {
        $path = $context->arguments['path'] ?? null;

        return \is_string($path) && '' !== $path ? $path : null;
    }

    /**
     * Get the tracker key prefix for a tool name.
     */
    private function toolPrefix(string $toolName): string
    {
        // bash tools use command-based classification
        // write/edit/read tools use path-based classification
        // For the tracker key, we use the tool name itself as a prefix
        // since the actual category (destructive, write_outside, etc.)
        // is unknown until after classification.
        //
        // The onApprovalAnswered() receives the actual category in the
        // approval context, so the tracker key prefix is informational.
        return $toolName;
    }

    /**
     * Check whether the runtime has an approval channel available.
     *
     * Interactive TUI contexts set HATFIELD_APPROVAL_CHANNEL=controller when
     * spawning the agent process so that all messenger consumers inherit it
     * and SafeGuard can prompt for approval instead of auto-blocking.
     *
     * Headless/worker contexts without this signal default to fail-closed.
     */
    private function hasApprovalChannel(): bool
    {
        $channel = getenv('HATFIELD_APPROVAL_CHANNEL');

        return \is_string($channel) && '' !== $channel;
    }

    /**
     * Check if a decision kind is policy-relaxable (approvable via HITL).
     */
    private function isRelaxable(SafeGuardDecisionKind $kind): bool
    {
        return \in_array($kind, self::RELAXABLE_CATEGORIES, true);
    }

    /**
     * Create a friendly category name for the approval prompt.
     */
    private function friendlyCategory(SafeGuardDecisionKind $kind): string
    {
        return match ($kind) {
            SafeGuardDecisionKind::Destructive => 'destructive command',
            SafeGuardDecisionKind::DangerousGit => 'dangerous git operation',
            SafeGuardDecisionKind::SensitiveInfo => 'sensitive information access',
            SafeGuardDecisionKind::CustomDangerous => 'custom dangerous operation',
            SafeGuardDecisionKind::WriteOutsideCwd => 'write outside working directory',
            SafeGuardDecisionKind::ProtectedRead => 'protected file read',
            default => 'operation',
        };
    }

    /**
     * Create a block DTO from a SafeGuard decision.
     */
    private function block(Policy\SafeGuardDecision $decision): ToolCallDecisionDTO
    {
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
     * Resolve the pattern to persist from the answer context.
     *
     * For command-based categories, uses the command text.
     * For path-based categories, uses the path text.
     *
     * @return string|null null if the pattern cannot be determined
     */
    private function resolvePatternFromAnswer(string $category, ApprovalAnswerContextDTO $context): ?string
    {
        $command = $context->approvalContext['command'] ?? null;
        $path = $context->approvalContext['path'] ?? null;

        if (\is_string($command) && '' !== $command) {
            return $command;
        }

        if (\is_string($path) && '' !== $path) {
            return $path;
        }

        return null;
    }
}
