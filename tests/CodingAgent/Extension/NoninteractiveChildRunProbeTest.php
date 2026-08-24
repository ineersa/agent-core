<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Extension\NoninteractiveChildRunProbe;
use PHPUnit\Framework\TestCase;

final class NoninteractiveChildRunProbeTest extends TestCase
{
    public function testDetectsNoninteractiveChildFromFirstEventWithoutReadingFullHistory(): void
    {
        $runId = 'child-run-1';
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())
            ->method('firstFor')
            ->with($runId)
            ->willReturn($this->runStarted($runId, 'agent_child', false));
        $store->expects($this->never())->method('allFor');

        $probe = new NoninteractiveChildRunProbe($store, AttributeSerializerValidatorTestFactory::denormalizer());

        $this->assertTrue($probe->isNoninteractiveChildRun($runId));
    }

    public function testReturnsFalseForMissingOrEmptyRunIds(): void
    {
        $store = new InMemoryEventStore();
        $probe = new NoninteractiveChildRunProbe($store, AttributeSerializerValidatorTestFactory::denormalizer());

        $this->assertFalse($probe->isNoninteractiveChildRun(null));
        $this->assertFalse($probe->isNoninteractiveChildRun(''));
        $this->assertFalse($probe->isNoninteractiveChildRun('missing-run'));
        $this->assertSame(1, $store->firstForCalls);
        $this->assertSame(0, $store->allForCalls);
    }

    public function testReturnsFalseForParentAndInteractiveChild(): void
    {
        $store = new InMemoryEventStore();
        $store->seed($this->runStarted('parent-run', 'parent', true));
        $store->seed($this->runStarted('interactive-child-run', 'agent_child', true));
        $probe = new NoninteractiveChildRunProbe($store, AttributeSerializerValidatorTestFactory::denormalizer());

        $this->assertFalse($probe->isNoninteractiveChildRun('parent-run'));
        $this->assertFalse($probe->isNoninteractiveChildRun('interactive-child-run'));
        $this->assertSame(2, $store->firstForCalls);
        $this->assertSame(0, $store->allForCalls);
    }

    public function testReturnsFalseWhenFirstEventIsNotRunStarted(): void
    {
        $runId = 'wrong-first-event';
        $store = new InMemoryEventStore();
        $store->seed(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::TurnStart->value,
            payload: [],
            createdAt: new \DateTimeImmutable(),
        ));
        $store->seed($this->runStarted($runId, 'agent_child', false, 2));
        $probe = new NoninteractiveChildRunProbe($store, AttributeSerializerValidatorTestFactory::denormalizer());

        $this->assertFalse($probe->isNoninteractiveChildRun($runId));
        $this->assertSame(1, $store->firstForCalls);
        $this->assertSame(0, $store->allForCalls);
    }

    private function runStarted(string $runId, string $kind, bool $interactive, int $seq = 1): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => $kind,
                            'interactive' => $interactive,
                            'parent_run_id' => 'parent-1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_child1',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => []],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        );
    }
}
