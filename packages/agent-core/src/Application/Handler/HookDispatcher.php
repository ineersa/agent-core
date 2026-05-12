<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Event\BoundaryHookEvent;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
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

        $resolved = $this->denormalizer->denormalize(
            $event->context,
            AfterTurnCommitHookContext::class,
            null,
            [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true],
        );

        \assert($resolved instanceof AfterTurnCommitHookContext);

        foreach ($this->registry->all() as $subscriber) {
            $resolved = $subscriber->handleAfterTurnCommit($resolved);
        }

        return $resolved;
    }
}
