<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Protocol;

use Ineersa\CodingAgent\Runtime\Protocol\RunLeafChangedEventFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunLeafChangedEventFactory::class)]
final class RunLeafChangedEventFactoryTest extends TestCase
{
    public function testCreateBuildsRunLeafChangedPayload(): void
    {
        $event = RunLeafChangedEventFactory::create('run-1', 42, 3);

        $this->assertSame(RuntimeEventTypeEnum::RunLeafChanged->value, $event->type);
        $this->assertSame('run-1', $event->runId);
        $this->assertSame(42, $event->seq);
        $this->assertSame(3, $event->payload['turn_no']);
        $this->assertSame(42, $event->payload['leaf_set_seq']);
    }
}
