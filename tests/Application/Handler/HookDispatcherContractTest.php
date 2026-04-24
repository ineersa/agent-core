<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\BoundaryHookEvent;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class HookDispatcherContractTest extends TestCase
{
    public function testAfterTurnCommitSubscribersCanObserveAndMutateContext(): void
    {
        $subscriber = new class implements HookSubscriberInterface {
            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                return new AfterTurnCommitHookContext(
                    runId: $context->runId,
                    turnNo: $context->turnNo,
                    status: 'mutated-by-subscriber',
                    events: $context->events,
                    effectsCount: $context->effectsCount + 1,
                );
            }
        };

        $serializer = $this->serializer();

        $dispatcher = new HookDispatcher(
            new HookSubscriberRegistry([$subscriber]),
            new EventDispatcher(),
            $serializer,
            $serializer,
        );

        $result = $dispatcher->dispatchAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-stage-01',
            turnNo: 2,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(seq: 7, type: 'agent_end')],
            effectsCount: 3,
        ));

        self::assertSame('run-stage-01', $result->runId);
        self::assertSame('mutated-by-subscriber', $result->status);
        self::assertSame(4, $result->effectsCount);
        self::assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $result->events);
    }

    public function testEventDispatcherListenerCanMutatePayloadBeforeSubscribers(): void
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(BoundaryHookName::AFTER_TURN_COMMIT, static function (BoundaryHookEvent $event): void {
            $event->context['status'] = 'mutated-by-listener';
            $event->context['effects_count'] = 10;
        });

        $serializer = $this->serializer();

        $dispatcher = new HookDispatcher(
            new HookSubscriberRegistry([]),
            $eventDispatcher,
            $serializer,
            $serializer,
        );

        $result = $dispatcher->dispatchAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-stage-02',
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(seq: 1, type: 'run_started')],
            effectsCount: 1,
        ));

        self::assertSame('mutated-by-listener', $result->status);
        self::assertSame(10, $result->effectsCount);
        self::assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $result->events);
    }

    private function serializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
        ]);
    }
}
