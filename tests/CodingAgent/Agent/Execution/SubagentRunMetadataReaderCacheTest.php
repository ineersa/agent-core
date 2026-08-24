<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Thesis: successful immutable RunStarted metadata is cached process-locally;
 * missing/malformed results are not cached.
 */
final class SubagentRunMetadataReaderCacheTest extends TestCase
{
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->denormalizer = AttributeSerializerValidatorTestFactory::denormalizer();
    }

    public function testRepeatedSuccessfulReadsCallAllForOnce(): void
    {
        $inner = new InMemoryEventStore();
        $inner->seed($this->runStarted('child-1', child: true));
        $store = new CountingEventStore($inner);
        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);

        $this->assertTrue($reader->isAgentChild('child-1'));
        $this->assertSame('parent-1', $reader->readParentRunId('child-1'));
        $this->assertSame(['bash'], $reader->readAllowedTools('child-1'));
        $this->assertSame([], $reader->readAllowedExtensions('child-1'));
        $this->assertNotNull($reader->readRunStartedMetadata('child-1'));

        $this->assertSame(1, $store->allForCounts['child-1'] ?? 0);
    }

    public function testValidParentAndChildCacheIndependently(): void
    {
        $inner = new InMemoryEventStore();
        $inner->seed($this->runStarted('parent-1', child: false));
        $inner->seed($this->runStarted('child-2', child: true, parentRunId: 'parent-1'));
        $store = new CountingEventStore($inner);
        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);

        $this->assertFalse($reader->isAgentChild('parent-1'));
        $this->assertTrue($reader->isAgentChild('child-2'));
        $this->assertFalse($reader->isAgentChild('parent-1'));
        $this->assertTrue($reader->isAgentChild('child-2'));

        $this->assertSame(1, $store->allForCounts['parent-1'] ?? 0);
        $this->assertSame(1, $store->allForCounts['child-2'] ?? 0);
    }

    public function testMissingResultIsNotCachedAndBecomesVisibleAfterAppend(): void
    {
        $inner = new InMemoryEventStore();
        $store = new CountingEventStore($inner);
        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);

        $this->assertNull($reader->readRunStartedMetadata('late-child'));
        $this->assertFalse($reader->isAgentChild('late-child'));
        $this->assertSame(2, $store->allForCounts['late-child'] ?? 0);

        $inner->seed($this->runStarted('late-child', child: true));

        $this->assertTrue($reader->isAgentChild('late-child'));
        $this->assertSame(['bash'], $reader->readAllowedTools('late-child'));
        $this->assertSame(3, $store->allForCounts['late-child'] ?? 0);
        // Successful decode is now cached.
        $this->assertTrue($reader->isAgentChild('late-child'));
        $this->assertSame(3, $store->allForCounts['late-child'] ?? 0);
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
        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);

        try {
            $reader->readRunStartedMetadata('broken-child');
            $this->fail('Expected malformed RunStarted metadata to throw');
        } catch (\Throwable $first) {
            $this->assertTrue(
                $first instanceof SerializerExceptionInterface || $first instanceof \InvalidArgumentException,
                $first::class,
            );
        }
        $this->assertSame(1, $store->allForCounts['broken-child'] ?? 0);

        // Simulate repair by replacing the store contents with a valid event.
        $repaired = new InMemoryEventStore();
        $repaired->seed($this->runStarted('broken-child', child: true));
        $store->replaceInner($repaired);

        $this->assertTrue($reader->isAgentChild('broken-child'));
        $this->assertSame(2, $store->allForCounts['broken-child'] ?? 0);
    }

    public function testCacheIsBoundedAndEvictionCausesReread(): void
    {
        $inner = new InMemoryEventStore();
        for ($i = 0; $i < 65; ++$i) {
            $inner->seed($this->runStarted('run-'.$i, child: 0 === $i % 2, parentRunId: 'parent-'.$i));
        }
        $store = new CountingEventStore($inner);
        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);

        for ($i = 0; $i < 65; ++$i) {
            $reader->isAgentChild('run-'.$i);
        }
        $this->assertSame(1, $store->allForCounts['run-0'] ?? 0);
        $this->assertSame(1, $store->allForCounts['run-64'] ?? 0);

        // run-0 should have been FIFO-evicted by inserting run-64.
        $this->assertTrue($reader->isAgentChild('run-0'));
        $this->assertSame(2, $store->allForCounts['run-0'] ?? 0);
        // Still-cached entry should not reread.
        $this->assertTrue($reader->isAgentChild('run-64'));
        $this->assertSame(1, $store->allForCounts['run-64'] ?? 0);
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
    public array $allForCounts = [];

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

    public function allFor(string $runId): array
    {
        $this->allForCounts[$runId] = ($this->allForCounts[$runId] ?? 0) + 1;

        return $this->inner->allFor($runId);
    }
}
