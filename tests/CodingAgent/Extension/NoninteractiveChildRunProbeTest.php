<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\NoninteractiveChildRunProbe;
use PHPUnit\Framework\TestCase;

final class NoninteractiveChildRunProbeTest extends TestCase
{
    public function testDetectsNoninteractiveChildFromRunStartedMetadata(): void
    {
        $runId = 'child-run-1';
        $event = new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'interactive' => false,
                            'parent_run_id' => 'parent-1',
                        ],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        );

        $childStore = $this->createStub(EventStoreInterface::class);
        $childStore->method('allFor')->willReturn([$event]);

        $emptyStore = $this->createStub(EventStoreInterface::class);
        $emptyStore->method('allFor')->willReturn([]);

        $probeChild = new NoninteractiveChildRunProbe($childStore);
        $probeEmpty = new NoninteractiveChildRunProbe($emptyStore);

        $this->assertTrue($probeChild->isNoninteractiveChildRun($runId));
        $this->assertFalse($probeEmpty->isNoninteractiveChildRun('parent-1'));
    }

    public function testInteractiveChildReturnsFalseFromProbe(): void
    {
        $runId = 'child-run-interactive';
        $event = new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'interactive' => true,
                            'parent_run_id' => 'parent-1',
                        ],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        );

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn([$event]);
        $probe = new NoninteractiveChildRunProbe($store);

        $this->assertFalse($probe->isNoninteractiveChildRun($runId));
    }
}
