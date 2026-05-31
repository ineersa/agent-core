<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Mapping;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Mapping\HitlMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HitlMappingSubscriber — maps AgentCore HITL events to
 * runtime protocol events, including the new agent_command_applied
 * subscriber for human_response answers.
 */
final class HitlMappingSubscriberTest extends TestCase
{
    private HitlMappingSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new HitlMappingSubscriber();
    }

    public function testOnWaitingHumanMapsToHumanInputRequested(): void
    {
        $runEvent = new RunEvent(
            runId: 'run-1',
            seq: 5,
            turnNo: 1,
            type: 'waiting_human',
            payload: [
                'question_id' => 'q-abc',
                'prompt' => 'Allow rm -rf /tmp?',
                'schema' => ['type' => 'string', 'enum' => ['yes', 'no']],
                'tool_name' => 'bash',
            ],
        );

        $event = new RunEventMappingEvent($runEvent);
        $this->subscriber->onWaitingHuman($event);

        $this->assertTrue($event->handled);
        $this->assertNotNull($event->mappedRuntimeEvent);
        $this->assertSame(
            RuntimeEventTypeEnum::HumanInputRequested->value,
            $event->mappedRuntimeEvent->type,
        );
        $this->assertSame('q-abc', $event->mappedRuntimeEvent->payload['question_id']);
        $this->assertSame('Allow rm -rf /tmp?', $event->mappedRuntimeEvent->payload['prompt']);
        $this->assertArrayHasKey('schema', $event->mappedRuntimeEvent->payload);
    }

    public function testOnWaitingHumanSkipsWhenHandled(): void
    {
        $runEvent = new RunEvent(
            runId: 'run-1',
            seq: 5,
            turnNo: 1,
            type: 'waiting_human',
            payload: ['question_id' => 'q-abc'],
        );

        $event = new RunEventMappingEvent($runEvent);
        $event->handled = true;

        $this->subscriber->onWaitingHuman($event);

        $this->assertNull($event->mappedRuntimeEvent);
    }

    public function testOnAgentCommandAppliedMapsHumanResponseToAnswered(): void
    {
        $runEvent = new RunEvent(
            runId: 'run-1',
            seq: 5,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'human_response',
                'question_id' => 'q-abc',
                'answer' => 'Allow once',
            ],
        );

        $event = new RunEventMappingEvent($runEvent);
        $this->subscriber->onAgentCommandApplied($event);

        $this->assertTrue($event->handled);
        $this->assertNotNull($event->mappedRuntimeEvent);
        $this->assertSame(
            RuntimeEventTypeEnum::HumanInputAnswered->value,
            $event->mappedRuntimeEvent->type,
        );
        $this->assertSame('q-abc', $event->mappedRuntimeEvent->payload['question_id']);
        $this->assertSame('Allow once', $event->mappedRuntimeEvent->payload['answer']);
    }

    public function testOnAgentCommandAppliedSkipsNonHumanResponse(): void
    {
        $runEvent = new RunEvent(
            runId: 'run-1',
            seq: 5,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: ['kind' => 'continue'],
        );

        $event = new RunEventMappingEvent($runEvent);
        $this->subscriber->onAgentCommandApplied($event);

        $this->assertFalse($event->handled);
        $this->assertNull($event->mappedRuntimeEvent);
    }

    public function testOnAgentCommandAppliedSkipsWhenHandled(): void
    {
        $runEvent = new RunEvent(
            runId: 'run-1',
            seq: 5,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'human_response',
                'question_id' => 'q-abc',
                'answer' => 'Deny',
            ],
        );

        $event = new RunEventMappingEvent($runEvent);
        $event->handled = true;

        $this->subscriber->onAgentCommandApplied($event);

        // Should not modify mappedRuntimeEvent when already handled
        $this->assertNull($event->mappedRuntimeEvent);
    }
}
