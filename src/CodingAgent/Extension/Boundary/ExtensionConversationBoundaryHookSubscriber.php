<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Boundary;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;

/**
 * Bridges AgentCore after-turn commit dispatch to conversation-boundary hooks.
 *
 * AfterTurnCommit remains available for file-rewind and other observers.
 * This subscriber only projects terminal conversation boundaries.
 *
 * @internal
 */
final readonly class ExtensionConversationBoundaryHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private ConversationBoundaryNotifier $notifier,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $events = [];
        foreach ($context->events as $summary) {
            $events[] = new RunEvent(
                runId: $context->runId,
                seq: $summary->seq,
                turnNo: $context->turnNo,
                type: $summary->type,
                // Preserve allocated-seq summaries' payloads when present.
                // AfterTurnCommitHookContext::fromRunState stores them.
                payload: $summary->payload,
            );
        }

        // Fallback only when a terminal agent_end summary lost its reason
        // payload (for example after a serializer round-trip). Prefer the
        // committed event reason over RunStatus.
        $events = $this->hydrateTerminalPayloadFromStatus($context, $events);
        $this->notifier->notifyPersistedBatch($context->runId, $events);

        return $context;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function hydrateTerminalPayloadFromStatus(AfterTurnCommitHookContext $context, array $events): array
    {
        $reason = match ($context->status) {
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => null,
        };
        if (null === $reason) {
            return $events;
        }

        $hydrated = [];
        foreach ($events as $event) {
            if ('agent_end' === $event->type && [] === $event->payload) {
                $hydrated[] = new RunEvent(
                    runId: $event->runId,
                    seq: $event->seq,
                    turnNo: $event->turnNo,
                    type: $event->type,
                    payload: ['reason' => $reason],
                    createdAt: $event->createdAt,
                );
                continue;
            }
            $hydrated[] = $event;
        }

        return $hydrated;
    }
}
