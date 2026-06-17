<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;

/**
 * Routes human approval answers to the originating SafeGuard hook at
 * commit time in the RUN_CONTROL consumer process (where ApplyCommand
 * commits) — a DIFFERENT process from the TOOL consumer where pending
 * approvals are registered by SafeGuardToolCallHook.
 *
 * The shared CachedApprovalLedger (cache.approvals pool backed by the
 * .hatfield messenger SQLite) bridges this process boundary:
 *   1. TOOL consumer      registerPending() for RequireApproval
 *   2. RUN_CONTROL consume resolveApproval() ← reads shared cache
 *   3. RUN_CONTROL consume markApproved()    → writes shared cache
 *   4. TOOL consumer      consumeApproval()  ← reads shared cache on retry
 *
 * This subscriber fires synchronously inside RunCommit::commit(), BEFORE
 * the postCommit AdvanceRun retry. It scans committed events for
 * 'agent_command_applied' with kind=human_response, resolves the pending
 * approval from the SHARED cache, calls onApprovalAnswered on the
 * locally-looked-up hook instance (identified by hook class name stored
 * at registration), then writes the approved decision back to the shared
 * cache so the retry in the TOOL consumer can see it.
 *
 * This replaces the previous polling-based ExtensionApprovalAnswerSubscriber
 * which ran in the controller process's RuntimeEventTranslator::translate()
 * drain loop — also the wrong process. That cross-process gap caused
 * SafeGuard approvals to always be ignored (issue #130).
 *
 * Registered via the 'agent_core.hook_subscriber' tag in services.yaml.
 *
 * @see ExtensionHookRegistry::resolveApproval()
 * @see ApprovalAnswerHookInterface::onApprovalAnswered()
 */
final readonly class SafeGuardApprovalCommitSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $runId = $context->runId;

        foreach ($context->events as $event) {
            if ('agent_command_applied' !== $event->type) {
                continue;
            }

            $payload = $event->payload ?? [];
            $kind = (string) ($payload['kind'] ?? '');

            if ('human_response' !== $kind) {
                continue;
            }

            $questionId = (string) ($payload['question_id'] ?? '');
            if ('' === $questionId) {
                continue;
            }

            // Cross-process resolve: reads from shared cache (via
            // CachedApprovalLedger) using runId, looks up the live
            // hook instance by hookId from the local registry.
            $entry = $this->hookRegistry->resolveApproval($questionId, $runId);
            if (null === $entry) {
                continue;
            }

            if (!$entry->hook instanceof ApprovalAnswerHookInterface) {
                continue;
            }

            $answer = (string) ($payload['answer'] ?? '');

            // Step 1: Call onApprovalAnswered so the hook processes the
            // answer in-process (e.g., SafeGuard writes settings.yaml for
            // "Always allow" persistence via SafeGuardPolicyWriter).
            $entry->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: $answer,
                toolName: (string) ($entry->details['tool_name'] ?? ''),
                approvalContext: $entry->details,
            ));

            // Step 2: Write the approved decision to the shared cache so
            // the retry (in a different consumer process) can see it via
            // ExtensionToolHookEventSubscriber's cache pre-check.
            //
            // For "Allow once" and "Always allow": write approved entry.
            // For "Deny": do NOT write (the pending entry was already
            // removed from cache by resolveApproval).
            if ('Deny' !== $answer) {
                $operationKey = (string) ($entry->details['operation_key'] ?? '');
                if ('' !== $operationKey) {
                    $this->hookRegistry->markApproved($runId, $operationKey);
                }
            }
        }

        return $context;
    }
}
