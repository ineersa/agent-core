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

        self::assertInstanceOf(RunEvent::class, $event);
        self::assertSame('run-ext', $event->runId);
        self::assertSame(1, $event->seq);
        self::assertSame(0, $event->turnNo);
        self::assertSame('ext_compaction_start', $event->type);
        self::assertSame(['strategy' => 'summary'], $event->payload);
    }

    public function testIsExtensionEventWithDefaultPrefix(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'ext_foo');

        self::assertTrue($event->isExtensionEvent());
    }

    public function testIsExtensionEventWithCustomPrefix(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'hook_pre_llm');

        self::assertTrue($event->isExtensionEvent('hook_'));
        self::assertFalse($event->isExtensionEvent('ext_'));
    }

    public function testIsExtensionEventForCoreType(): void
    {
        $event = new RunEvent(runId: 'r', seq: 1, turnNo: 0, type: 'agent_start');

        self::assertFalse($event->isExtensionEvent());
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
