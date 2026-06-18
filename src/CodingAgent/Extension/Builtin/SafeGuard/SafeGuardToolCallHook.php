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
 * Implements ApprovalAnswerHookInterface to receive the human's answer,
 * update the ApprovalSessionTracker accordingly, and resolve the answer
 * into a tool-execution decision. Answer labels carry icon glyphs
 * (e.g. '✅ Allow once') and are reverse-mapped to canonical actions:
 * - allow_once / always_allow → allow() — handler runs
 * - deny → block('safeguard_denied', ...) — denied
 * - cancel (ESC / user cancel) → block('safeguard_cancelled', ...) — cancelled
 * - 'Always allow' also persists the pattern to policy file via onApprovalAnswered.
 */
final readonly class SafeGuardToolCallHook implements ToolCallHookInterface, ApprovalAnswerHookInterface
{
    /**
     * Canonical action → display label with icon glyph.
     *
     * The display labels (values) go into the ToolQuestion schema enum so the
     * TUI renders icon-bearing buttons. resolveApprovalAnswer() reverse-maps
     * the label back to the canonical action for tool-execution decisions.
     * onApprovalAnswered() also reverse-maps so side effects (persistence,
     * tracker) use canonical values — icon glyphs never leak into
     * settings.yaml or logs.
     *
     * @var array<string, string>
     */
    private const array APPROVAL_OPTIONS = [
        'allow_once' => '✅ Allow once',
        'always_allow' => '📌 Always allow',
        'deny' => '❌ Block',
    ];

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
        // If the operation was just approved (same-process), consume
        // the approval and allow. For cross-process approvals, the
        // ExtensionToolHookEventSubscriber checks the shared cache
        // BEFORE marking this decision as RequireApproval.
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
                    'enum' => array_values(self::APPROVAL_OPTIONS),
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

        // Reverse-map the icon-bearing label to the canonical action
        // so side effects (persistence, tracker) use canonical values —
        // icon glyphs never leak into settings.yaml or logs.
        $canonical = array_search($context->answer, self::APPROVAL_OPTIONS, true);

        if ('deny' === $canonical || 'cancel' === $context->answer) {
            // removeByQuestionId cleans both the pending mapping and the
            // approved entry (if any) so stale state cannot accumulate.
            $this->approvalTracker->removeByQuestionId($context->questionId);

            return;
        }

        if ('allow_once' === $canonical) {
            // approveByQuestionId resolves the operationKey from the
            // pendingByQuestionId mapping, marks approved, and cleans
            // the pending entry in one step.
            $this->approvalTracker->approveByQuestionId($context->questionId);

            return;
        }

        if ('always_allow' === $canonical) {
            $this->approvalTracker->approveByQuestionId($context->questionId);

            // Persist to policy file for future sessions.
            // resolvePatternFromAnswer reads command/path from approvalContext,
            // NOT from the answer text — so icon glyphs cannot leak into
            // the persisted settings.
            $category = (string) ($context->approvalContext['category'] ?? '');
            $pattern = $this->resolvePatternFromAnswer($category, $context);

            if (null !== $pattern && null !== $this->policyWriter) {
                $this->policyWriter->addAllowPattern($category, $pattern);
            }

            return;
        }

        // Unrecognized answers (null, empty, or unknown values) are
        // intentionally ignored: no approval is recorded and the
        // pending entry in the tracker remains, leaving the operation
        // blocked. This is a fail-closed safety guard — only explicit
        // Allow once / Always allow / Deny / Cancel answers mutate state.
        // If the question is retried (re-answered), a new answer will
        // arrive on a fresh call to this method.
        //
        // The guard is intentionally sparse: no logging, no exception.
        // A stray/corrupted answer should not crash the run.
    }

    public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
    {
        $answer = $context->answer;

        // Reverse-map icon-bearing label to canonical action.
        // The TUI sends labels with emoji icons (e.g. '✅ Allow once');
        // we map back to the canonical action for the decision.
        $canonical = array_search($answer, self::APPROVAL_OPTIONS, true);

        if ('allow_once' === $canonical || 'always_allow' === $canonical) {
            return ToolCallDecisionDTO::allow();
        }

        if ('deny' === $canonical) {
            return ToolCallDecisionDTO::block(
                reason: 'safeguard_denied',
                details: [
                    'category' => $context->approvalContext['category'] ?? '',
                    'intercepted' => true,
                    'message' => \sprintf(
                        'Tool "%s" was denied by SafeGuard: the human denied the operation.',
                        $context->toolName,
                    ),
                ],
            );
        }

        if ('cancel' === $answer) {
            return ToolCallDecisionDTO::block(
                reason: 'safeguard_cancelled',
                details: [
                    'category' => $context->approvalContext['category'] ?? '',
                    'intercepted' => true,
                    'message' => \sprintf(
                        'Tool "%s" was cancelled by the user.',
                        $context->toolName,
                    ),
                ],
            );
        }

        // Unrecognized answer — fail closed
        return ToolCallDecisionDTO::block(
            reason: 'safeguard_unknown_answer',
            details: [
                'category' => $context->approvalContext['category'] ?? '',
                'intercepted' => true,
                'message' => \sprintf(
                    'Tool "%s" was denied by SafeGuard: unknown answer "%s".',
                    $context->toolName,
                    $answer,
                ),
            ],
        );
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
     * This is a capability signal, NOT a security boundary. An approval
     * channel means there is a human or broker that can receive questions
     * and relay answers back (via answer_human commands). Without it,
     * RequireApproval decisions would hang the run in WaitingHuman forever.
     *
     * Interactive TUI contexts set HATFIELD_APPROVAL_CHANNEL=controller when
     * spawning the agent process so that all messenger consumers inherit it
     * and SafeGuard can prompt for approval instead of auto-blocking.
     *
     * Headless/worker contexts without this signal default to fail-closed
     * (auto-block) because no one is available to answer the question.
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
