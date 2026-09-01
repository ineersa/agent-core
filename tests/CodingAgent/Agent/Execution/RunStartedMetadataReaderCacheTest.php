<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Thesis: successful immutable RunStarted metadata is cached process-locally;
 * missing/malformed results are not cached. Hot child/parent classification is
 * owned by RunRelationshipReader and is not covered here.
 */
final class RunStartedMetadataReaderCacheTest extends TestCase
{
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->denormalizer = AttributeSerializerValidatorTestFactory::denormalizer();
    }

    public function testRepeatedSuccessfulReadsCallFirstForOnce(): void
    {
        $inner = new InMemoryEventStore();
        $inner->seed($this->runStarted('child-1', child: true));
        $store = new CountingEventStore($inner);
        $reader = new RunStartedMetadataReader($store, $this->denormalizer);

        $this->assertSame(['bash'], $reader->readAllowedTools('child-1'));
        $this->assertSame([], $reader->readAllowedExtensions('child-1'));
        $this->assertNotNull($reader->readRunStartedMetadata('child-1'));
        $this->assertSame('deepseek/deepseek-v4-flash', $reader->readRunStartedMetadata('child-1')?->model);

        $this->assertSame(1, $store->firstForCounts['child-1'] ?? 0);
    }

    public function testValidParentAndChildCacheIndependently(): void
    {
        $inner = new InMemoryEventStore();
        $inner->seed($this->runStarted('parent-1', child: false));
        $inner->seed($this->runStarted('child-2', child: true, parentRunId: 'parent-1'));
        $store = new CountingEventStore($inner);
        $reader = new RunStartedMetadataReader($store, $this->denormalizer);

        $this->assertNull($reader->readAllowedTools('parent-1'));
        $this->assertSame(['bash'], $reader->readAllowedTools('child-2'));
        $this->assertNull($reader->readAllowedTools('parent-1'));
        $this->assertSame(['bash'], $reader->readAllowedTools('child-2'));

        $this->assertSame(1, $store->firstForCounts['parent-1'] ?? 0);
        $this->assertSame(1, $store->firstForCounts['child-2'] ?? 0);
    }

    public function testMissingResultIsNotCachedAndBecomesVisibleAfterAppend(): void
    {
        $inner = new InMemoryEventStore();
        $store = new CountingEventStore($inner);
        $reader = new RunStartedMetadataReader($store, $this->denormalizer);

        $this->assertNull($reader->readRunStartedMetadata('late-child'));
        $this->assertNull($reader->readAllowedTools('late-child'));
        $this->assertSame(2, $store->firstForCounts['late-child'] ?? 0);

        $inner->seed($this->runStarted('late-child', child: true));

        $this->assertSame(['bash'], $reader->readAllowedTools('late-child'));
        $this->assertSame(3, $store->firstForCounts['late-child'] ?? 0);
        $this->assertSame(['bash'], $reader->readAllowedTools('late-child'));
        $this->assertSame(3, $store->firstForCounts['late-child'] ?? 0);
    }

    public function testMalformedResultIsNotCachedAndRetriesAfterRepair(): void
    {
        $inner = new InMemoryEventStore();
        $inner->seed(new RunEvent(
            runId: 'broken-child',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                        ],
                        'model' => 'm',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => ['bash']],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));
        $store = new CountingEventStore($inner);
        $reader = new RunStartedMetadataReader($store, $this->denormalizer);

        try {
            $reader->readRunStartedMetadata('broken-child');
            $this->fail('Expected malformed RunStarted metadata to throw');
        } catch (\Throwable $first) {
            $this->assertTrue(
                $first instanceof SerializerExceptionInterface || $first instanceof \InvalidArgumentException,
                $first::class,
            );
        }
        $this->assertSame(1, $store->firstForCounts['broken-child'] ?? 0);

        $repaired = new InMemoryEventStore();
        $repaired->seed($this->runStarted('broken-child', child: true));
        $store->replaceInner($repaired);

        $this->assertSame(['bash'], $reader->readAllowedTools('broken-child'));
        $this->assertSame(2, $store->firstForCounts['broken-child'] ?? 0);
    }

    public function testCacheIsBoundedAndEvictionCausesReread(): void
    {
        $inner = new InMemoryEventStore();
        for ($i = 0; $i < 65; ++$i) {
            $inner->seed($this->runStarted('run-'.$i, child: 0 === $i % 2, parentRunId: 'parent-'.$i));
        }
        $store = new CountingEventStore($inner);
        $reader = new RunStartedMetadataReader($store, $this->denormalizer);

        for ($i = 0; $i < 65; ++$i) {
            $reader->readRunStartedMetadata('run-'.$i);
        }
        $this->assertSame(1, $store->firstForCounts['run-0'] ?? 0);
        $this->assertSame(1, $store->firstForCounts['run-64'] ?? 0);

        $this->assertNotNull($reader->readRunStartedMetadata('run-0'));
        $this->assertSame(2, $store->firstForCounts['run-0'] ?? 0);
        $this->assertNotNull($reader->readRunStartedMetadata('run-64'));
        $this->assertSame(1, $store->firstForCounts['run-64'] ?? 0);
    }

    /**
     * @param non-empty-string $runId
     */
    private function runStarted(string $runId, bool $child, string $parentRunId = 'parent-1'): RunEvent
    {
        if ($child) {
            $payload = [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are a scout.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => $parentRunId,
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_abc123',
                            'interactive' => false,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => [
                                'mode' => 'none',
                                'tools' => [],
                            ],
                        ],
                        'extensions' => [],
                    ],
                ],
            ];
        } else {
            $payload = [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are hatfield.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'main',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash', 'read'],
                        ],
                    ],
                ],
            ];
        }

        return new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        );
    }
}

/**
 * @internal
 */
final class CountingEventStore implements EventStoreInterface
{
    /** @var array<string, int> */
    public array $firstForCounts = [];

    public function __construct(
        private EventStoreInterface $inner,
    ) {
    }

    public function replaceInner(EventStoreInterface $inner): void
    {
        $this->inner = $inner;
    }

    public function append(RunEvent $event): RunEvent
    {
        return $this->inner->append($event);
    }

    public function appendMany(array $events): array
    {
        return $this->inner->appendMany($events);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        return $this->inner->latestSequenceFor($runId);
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $this->firstForCounts[$runId] = ($this->firstForCounts[$runId] ?? 0) + 1;

        return $this->inner->firstFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return $this->inner->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        return [];
    }

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }
}
