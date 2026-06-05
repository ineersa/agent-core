<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Routes human approval answers back to the extension hook that
 * requested them.
 *
 * Listens to 'agent_command_applied' RunEvent dispatches from
 * RuntimeEventTranslator with kind=human_response. Resolves the originating
 * hook from ExtensionHookRegistry and calls onApprovalAnswered() if the
 * hook implements ApprovalAnswerHookInterface.
 *
 * Runs at priority 0; it is called before the RuntimeEventTranslator's
 * dispatch table processes the same event for mapping to HumanInputAnswered.
 */
final readonly class ExtensionApprovalAnswerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'agent_command_applied' => 'onAgentCommandApplied',
        ];
    }

    public function onAgentCommandApplied(RunEvent $event): void
    {
        $p = $event->payload;
        $kind = (string) ($p['kind'] ?? '');

        if ('human_response' !== $kind) {
            return;
        }

        $questionId = (string) ($p['question_id'] ?? '');

        if ('' === $questionId) {
            return;
        }

        $entry = $this->hookRegistry->resolveApproval($questionId);

        if (null === $entry) {
            return;
        }

        if (!$entry->hook instanceof ApprovalAnswerHookInterface) {
            return;
        }

        $entry->hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: (string) ($p['answer'] ?? ''),
            toolName: (string) ($entry->details['tool_name'] ?? ''),
            approvalContext: $entry->details,
        ));
    }
}
