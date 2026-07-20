<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardClassifier;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;

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
 * Implements ApprovalAnswerHookInterface to receive the human's answer and
 * resolve it into a tool-execution decision. Answer labels carry icon glyphs
 * (e.g. '✅ Allow') and are reverse-mapped to canonical actions:
 * - allow → allow() — exact original tool handler runs
 * - deny → block('safeguard_denied', ...) — denied
 * - cancel (ESC / user cancel) → block('safeguard_cancelled', ...) — cancelled
 *
 * onApprovalAnswered() is intentionally a no-op: interactive "Always allow"
 * persistence was removed. Static settings allowlists still apply via
 * SafeGuardPolicy/config.
 *
 * Authorization for Allow is durable on the resumed ExecuteToolCall
 * (typed ToolCallHumanInputAnswerDTO), not process-local tracker state.
 */
final readonly class SafeGuardToolCallHook implements ToolCallHookInterface, ApprovalAnswerHookInterface
{
    /**
     * Canonical action → display label with icon glyph.
     *
     * The display labels (values) go into the waiting_human schema enum so the
     * TUI renders icon-bearing buttons. resolveApprovalAnswer() reverse-maps
     * the label back to the canonical action for tool-execution decisions.
     *
     * @var array<string, string>
     */
    private const array APPROVAL_OPTIONS = [
        'allow' => '✅ Allow',
        'deny' => '❌ Deny',
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
        private string $cwd,
        private bool $autoDenyInNoninteractive = true,
        private string $settingsToolName = 'settings',
    ) {
    }

    public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
    {
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
        // - Settings set/remove always fail closed without an approval channel,
        //   regardless of auto_deny_in_noninteractive (no silent config writes).
        if ($this->isRelaxable($decision->kind)) {
            if ($this->shouldAutoDenyRelaxable($context)) {
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

            $settingsMutation = $this->isSettingsMutation($context);

            // Stable per exact call: run_id + tool_call_id (no tracker operation key).
            $questionId = \sprintf(
                'sg_%s',
                hash('sha256', \sprintf('%s|%s', $context->runId ?? '', $context->toolCallId)),
            );

            $categoryLabel = $settingsMutation ? 'settings mutation' : $this->friendlyCategory($decision->kind);

            return ToolCallDecisionDTO::requireApproval(
                prompt: \sprintf('Allow %s: %s?', $categoryLabel, $decision->reason),
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
                    'intercepted' => true,
                ],
            );
        }

        // Fallback: block everything else
        return $this->block($decision);
    }

    public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
    {
        // Documented no-op: interactive Always-allow persistence was removed.
        // Static allowlists still apply through SafeGuardPolicy/config load.
    }

    public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
    {
        $answer = $context->answer;

        // Reverse-map icon-bearing label to canonical action.
        // The TUI sends labels with emoji icons (e.g. '✅ Allow');
        // we map back to the canonical action for the decision.
        $canonical = array_search($answer, self::APPROVAL_OPTIONS, true);

        if ('allow' === $canonical) {
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

        // Canonical HITL cancel vocabulary (and explicit cancel labels).
        if ('cancel' === $answer || 'Cancelled by user' === $answer) {
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

        // Unrecognized answer — fail closed without echoing the raw answer.
        return ToolCallDecisionDTO::block(
            reason: 'safeguard_unknown_answer',
            details: [
                'category' => $context->approvalContext['category'] ?? '',
                'intercepted' => true,
                'message' => \sprintf(
                    'Tool "%s" was denied by SafeGuard: unrecognized approval answer.',
                    $context->toolName,
                ),
            ],
        );
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
     * Auto-deny relaxable operations when no human can answer approvals.
     */
    private function shouldAutoDenyRelaxable(ToolCallContextDTO $context): bool
    {
        // Settings mutations always require a live approval channel.
        if ($this->isSettingsMutation($context)) {
            return !$this->hasApprovalChannel();
        }

        if (!$this->autoDenyInNoninteractive) {
            return false;
        }

        if (true === ($context->metadata['noninteractive_child_run'] ?? false)) {
            return true;
        }

        return !$this->hasApprovalChannel();
    }

    private function isSettingsMutation(ToolCallContextDTO $context): bool
    {
        if ($context->toolName !== $this->settingsToolName) {
            return false;
        }

        $operation = $context->arguments['operation'] ?? null;

        return \is_string($operation) && \in_array($operation, ['set', 'remove'], true);
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
}
