<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Regression test: the production serializer MUST be able to round-trip
 * AfterTurnCommitHookContext without throwing InvalidArgumentException.
 *
 * Previously the serializer config lacked ArrayDenormalizer and
 * PhpDocExtractor, causing denormalize() to produce plain arrays for
 * the $events property instead of AfterTurnCommitEventSummary objects.
 * The constructor type check then threw:
 *   "events must be a list of AfterTurnCommitEventSummary."
 *
 * This test uses the exact normalizer/encoder stack as the production
 * config/packages/serializer.yaml to ensure the fix stays effective.
 */
final class AfterTurnCommitSerializerRegressionTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $propertyTypeExtractor = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        $this->serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
        ]);
    }

    public function testRoundTripPreservesEventTypes(): void
    {
        $original = new AfterTurnCommitHookContext(
            runId: 'test-run-01',
            turnNo: 3,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummary(seq: 1, type: 'run_started'),
                new AfterTurnCommitEventSummary(seq: 5, type: 'agent_end'),
            ],
            effectsCount: 2,
        );

        $normalized = $this->serializer->normalize($original);
        self::assertIsArray($normalized, 'Normalization should produce an array');

        /** @var AfterTurnCommitHookContext $restored */
        $restored = $this->serializer->denormalize(
            $normalized,
            AfterTurnCommitHookContext::class,
        );

        self::assertInstanceOf(AfterTurnCommitHookContext::class, $restored);
        self::assertSame($original->runId, $restored->runId);
        self::assertSame($original->turnNo, $restored->turnNo);
        self::assertSame($original->status, $restored->status);
        self::assertSame($original->effectsCount, $restored->effectsCount);
        self::assertCount(\count($original->events), $restored->events);
        self::assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $restored->events);
        self::assertSame(1, $restored->events[0]->seq);
        self::assertSame('run_started', $restored->events[0]->type);
        self::assertSame(5, $restored->events[1]->seq);
        self::assertSame('agent_end', $restored->events[1]->type);
    }

    public function testRoundTripWithEmptyEvents(): void
    {
        $original = new AfterTurnCommitHookContext(
            runId: 'test-run-02',
            turnNo: 0,
            status: 'completed',
            events: [],
            effectsCount: 0,
        );

        $normalized = $this->serializer->normalize($original);
        $restored = $this->serializer->denormalize(
            $normalized,
            AfterTurnCommitHookContext::class,
        );

        self::assertInstanceOf(AfterTurnCommitHookContext::class, $restored);
        self::assertSame([], $restored->events);
    }

    public function testRoundTripWithSingleEvent(): void
    {
        $original = new AfterTurnCommitHookContext(
            runId: 'test-run-03',
            turnNo: 1,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummary(seq: 42, type: 'turn_advanced'),
            ],
            effectsCount: 1,
        );

        $normalized = $this->serializer->normalize($original);
        $restored = $this->serializer->denormalize(
            $normalized,
            AfterTurnCommitHookContext::class,
        );

        self::assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $restored->events);
        self::assertSame(42, $restored->events[0]->seq);
    }

    /**
     * Verify that the denormalization does NOT throw InvalidArgumentException
     * even when events are provided as plain arrays (mimicking the EventDispatcher
     * listener mutation path).
     */
    public function testPlainArrayInputDoesNotThrow(): void
    {
        // This simulates what EventDispatcher listeners produce after normalizing,
        // mutating, and denormalizing: the context array may have plain arrays for events.
        $input = [
            'run_id' => 'test-run-04',
            'turn_no' => 2,
            'status' => 'failed',
            'events' => [
                ['seq' => 1, 'type' => 'run_started'],
            ],
            'effects_count' => 0,
        ];

        // Should not throw InvalidArgumentException
        $restored = $this->serializer->denormalize(
            $input,
            AfterTurnCommitHookContext::class,
        );

        self::assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $restored->events);
    }

    public function testAfterTurnCommitEventSummaryConstructsWithSeqAndTypeOnly(): void
    {
        // The $payload field was added for the now-deleted
        // SafeGuardApprovalCommitSubscriber but was never read in production
        // (AfterTurnCommitHookContext::fromRunState() explicitly excluded it).
        // With no commit-time subscriber needing event payload data, the
        // field is removed entirely.
        $event = new AfterTurnCommitEventSummary(seq: 10, type: 'agent_command_applied');
        self::assertSame(10, $event->seq);
        self::assertSame('agent_command_applied', $event->type);
    }
}
