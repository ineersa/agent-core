<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;

/**
 * Routes human approval answers to the originating SafeGuard hook at
 * commit time — IN THE SAME (worker) PROCESS that performs tool execution.
 *
 * This subscriber fires synchronously inside RunCommit::commit(), BEFORE
 * the postCommit AdvanceRun retry. It scans committed events for
 * 'agent_command_applied' with kind=human_response and routes each answer
 * to the originating hook via ExtensionHookRegistry::resolveApproval()
 * → ApprovalAnswerHookInterface::onApprovalAnswered().
 *
 * This replaces the previous polling-based ExtensionApprovalAnswerSubscriber
 * which ran in the controller process's RuntimeEventTranslator::translate()
 * drain loop — a DIFFERENT process than the tool-worker where pending
 * approvals live in ExtensionHookRegistry. That cross-process gap caused
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

            $entry = $this->hookRegistry->resolveApproval($questionId);
            if (null === $entry) {
                continue;
            }

            if (!$entry->hook instanceof ApprovalAnswerHookInterface) {
                continue;
            }

            $entry->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: (string) ($payload['answer'] ?? ''),
                toolName: (string) ($entry->details['tool_name'] ?? ''),
                approvalContext: $entry->details,
            ));
        }

        return $context;
    }
}
