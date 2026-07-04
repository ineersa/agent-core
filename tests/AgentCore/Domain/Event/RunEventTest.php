<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Event;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use PHPUnit\Framework\TestCase;

final class RunEventTest extends TestCase
{
    /* ─── RunEvent::extension() ─── */

    public function testExtensionEventFactoryCreatesExtensionEvent(): void
    {
        $event = RunEvent::extension(
            runId: 'run-ext',
            seq: 1,
            turnNo: 0,
            type: 'ext_compaction_start',
            payload: ['strategy' => 'summary'],
        );

        $this->assertInstanceOf(RunEvent::class, $event);
        $this->assertSame('run-ext', $event->runId);
        $this->assertSame(1, $event->seq);
        $this->assertSame(0, $event->turnNo);
        $this->assertSame('ext_compaction_start', $event->type);
        $this->assertSame(['strategy' => 'summary'], $event->payload);
    }

    public function testIsExtensionEventWithDefaultPrefix(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'ext_foo');

        $this->assertTrue($event->isExtensionEvent());
    }

    public function testIsExtensionEventWithCustomPrefix(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'hook_pre_llm');

        $this->assertTrue($event->isExtensionEvent('hook_'));
        $this->assertFalse($event->isExtensionEvent('ext_'));
    }

    public function testIsExtensionEventForCoreType(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'agent_start');

        $this->assertFalse($event->isExtensionEvent());
    }

    public function testExtensionEventWithInvalidPrefixThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must use');

        RunEvent::extension(
            runId: 'run-ext',
            seq: 1,
            turnNo: 0,
            type: 'my_custom_event',
        );
    }
}
