<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Application\Message\ConsumeSubagentProgressDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class CoordinationActionSerializationTest extends IsolatedKernelTestCase
{
    public function testConfiguredRunControlSerializerPreservesEverySupportedActionAndPreparedIdentity(): void
    {
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.native_php_serializer');
        $advance = AdvanceRunCoordinationFactory::create('run', 7, 'follow-up');
        $compact = new DispatchCoordinationMessageDTO(new CompactRun('run', 7, 'compact-stable', 1, 'compact-key', trigger: 'manual', customInstructions: 'Keep context'));
        $call = new ExecuteToolCall('run', 7, 'step', 1, 'effect-key', 'call', 'bash', ['command' => 'pwd'], 0);
        $actions = [
            $advance,
            $compact,
            new MarkCommandAppliedDTO('run', 'source-key'),
            new RegisterToolBatchDTO('run', 7, 'step', [$call], ['call' => 0], [], ['call' => true], 1),
            new ConsumeSubagentProgressDTO('lifecycle', 3, true, false, new \DateTimeImmutable('2026-10-03T18:00:00Z')),
            new ConsumeSubagentProgressDTO('lifecycle', 3, false, true, new \DateTimeImmutable('2026-10-03T18:00:00Z')),
            new DeliverDeferredSubagentBatchLifecycleMessage('lifecycle'),
        ];
        foreach ($actions as $action) {
            $encoded = $serializer->encode(new Envelope($action));
            $decoded = $serializer->decode($encoded)->getMessage();
            $this->assertEquals($action, $decoded);
            $this->assertSame($encoded, $serializer->encode(new Envelope($decoded)), 'Restoring an action must not allocate new identities or timestamps.');
        }
        $restoredAdvance = $serializer->decode($serializer->encode(new Envelope($advance)))->getMessage();
        $this->assertInstanceOf(DispatchCoordinationMessageDTO::class, $restoredAdvance);
        $this->assertSame($advance->message->stepId(), $restoredAdvance->message->stepId());
        $restoredBatch = $serializer->decode($serializer->encode(new Envelope($actions[3])))->getMessage();
        $this->assertInstanceOf(RegisterToolBatchDTO::class, $restoredBatch);
        $this->assertInstanceOf(ExecuteToolCall::class, $restoredBatch->effects[0]);
        $this->assertSame('call', $restoredBatch->effects[0]->toolCallId);
    }
}
