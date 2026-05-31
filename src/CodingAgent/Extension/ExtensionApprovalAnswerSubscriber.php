<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Routes human approval answers back to the extension hook that
 * requested them.
 *
 * Listens to 'agent_command_applied' AgentCore RunEvent mapping events
 * with kind=human_response. Resolves the originating hook from
 * ExtensionHookRegistry and calls onApprovalAnswered() if the hook
 * implements ApprovalAnswerHookInterface.
 *
 * This subscriber does NOT mark $event->handled = true — the
 * HitlMappingSubscriber still needs to process the same event to map
 * it to HumanInputAnswered for transcript projection.
 *
 * Runs at priority 20 (before HitlMappingSubscriber at priority 10
 * and CancelAndFallbackMappingSubscriber at priority 0) to ensure
 * answers are routed to hooks before the event is consumed for
 * mapping or fallback.
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
            'agent_command_applied' => ['onAgentCommandApplied', 20],
        ];
    }

    public function onAgentCommandApplied(RunEventMappingEvent $event): void
    {
        // Do NOT check $event->handled here — the HitlMappingSubscriber
        // will handle it. We read and route independently.
        // We also do NOT set $event->handled.

        $p = $event->runEvent->payload;
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
