<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Tests\Session\History\InMemoryHistoryProjectionStore;
use PHPUnit\Framework\TestCase;

final class NoninteractiveChildRunProbeTest extends TestCase
{
    public function testEmptyRunIdIsNotChild(): void
    {
        $probe = new RunStartedMetadataReader(new InMemoryHistoryProjectionStore());
        $this->assertFalse($probe->isNoninteractiveChildRun(null));
        $this->assertFalse($probe->isNoninteractiveChildRun(''));
    }

    public function testNoninteractiveChildIsDetected(): void
    {
        $store = new InMemoryHistoryProjectionStore();
        $store->initializeFromEvents('child-1', [$this->runStarted('child-1', interactive: false)]);
        $probe = new RunStartedMetadataReader($store);

        $this->assertTrue($probe->isNoninteractiveChildRun('child-1'));
        $this->assertSame(1, $store->getCalls);
        $this->assertTrue($probe->isNoninteractiveChildRun('child-1'));
        $this->assertSame(2, $store->getCalls);
    }

    public function testInteractiveChildIsNotDetected(): void
    {
        $store = new InMemoryHistoryProjectionStore();
        $store->initializeFromEvents('child-2', [$this->runStarted('child-2', interactive: true)]);
        $probe = new RunStartedMetadataReader($store);

        $this->assertFalse($probe->isNoninteractiveChildRun('child-2'));
    }

    public function testMissingProjectionFailsClosed(): void
    {
        $probe = new RunStartedMetadataReader(new InMemoryHistoryProjectionStore());
        $this->expectException(\RuntimeException::class);
        $probe->isNoninteractiveChildRun('missing');
    }

    private function runStarted(string $runId, bool $interactive): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent-1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_abc',
                            'interactive' => $interactive,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => ['bash']],
                        'extensions' => [],
                    ],
                ],
            ],
        );
    }
}
