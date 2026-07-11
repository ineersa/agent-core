<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Event\BoundaryHookEvent;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class HookDispatcher
{
    public function __construct(
        private HookSubscriberRegistry $registry,
        private EventDispatcherInterface $eventDispatcher,
        private NormalizerInterface $normalizer,
        private DenormalizerInterface $denormalizer,
    ) {
    }

    public function dispatchAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $payload = $this->normalizer->normalize($context);
        \assert(\is_array($payload));

        $event = new BoundaryHookEvent(BoundaryHookName::AFTER_TURN_COMMIT, $payload);
        $this->eventDispatcher->dispatch($event, BoundaryHookName::AFTER_TURN_COMMIT);

        $context = $this->restoreHookContext($event->context, $context);

        foreach ($this->registry->all() as $subscriber) {
            $context = $subscriber->handleAfterTurnCommit($context);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $mutatedPayload
     */
    private function restoreHookContext(array $mutatedPayload, AfterTurnCommitHookContext $fallback): AfterTurnCommitHookContext
    {
        $events = [];
        $rawEvents = $mutatedPayload['events'] ?? null;
        if (\is_array($rawEvents)) {
            foreach ($rawEvents as $eventData) {
                if (!\is_array($eventData)) {
                    continue;
                }

                $events[] = $this->denormalizer->denormalize($eventData, AfterTurnCommitEventSummary::class);
            }
        }

        if ([] === $events) {
            $events = $fallback->events;
        }

        $runId = \is_string($mutatedPayload['run_id'] ?? null) ? $mutatedPayload['run_id'] : $fallback->runId;
        $turnNo = \is_int($mutatedPayload['turn_no'] ?? null) ? $mutatedPayload['turn_no'] : $fallback->turnNo;
        $status = \is_string($mutatedPayload['status'] ?? null) ? $mutatedPayload['status'] : $fallback->status;
        $effectsCount = \is_int($mutatedPayload['effects_count'] ?? null) ? $mutatedPayload['effects_count'] : $fallback->effectsCount;

        return new AfterTurnCommitHookContext(
            runId: $runId,
            turnNo: $turnNo,
            status: $status,
            events: $events,
            effectsCount: $effectsCount,
        );
    }
}
