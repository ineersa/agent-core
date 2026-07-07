<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubagentRunMetadataReader::class)]
final class SubagentRunMetadataReaderTest extends TestCase
{
    public function testReadChildKindReturnsForkForForkChildRun(): void
    {
        $eventStore = $this->createStub(EventStoreInterface::class);
        $eventStore->method('allFor')->willReturn([
            new RunEvent(
                runId: 'child-run',
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'payload' => [
                        'metadata' => [
                            'session' => [
                                'kind' => 'agent_child',
                                'child_kind' => 'fork',
                                'parent_run_id' => 'parent-run',
                            ],
                        ],
                    ],
                ],
            ),
        ]);

        $reader = new SubagentRunMetadataReader($eventStore);
        $this->assertSame('fork', $reader->readChildKind('child-run'));
    }
}
