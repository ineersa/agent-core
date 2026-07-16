<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ContinueForkDeferredPrelaunchMessage;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Test thesis: ContinueForkDeferredPrelaunchMessage must be durable/async on run_control
 * (like other deferred lifecycle messages), not handled synchronously on dispatch.
 *
 * @coversNothing
 */
final class ForkDeferredPrelaunchRunControlRoutingTest extends IsolatedKernelTestCase
{
    public function testContinueForkDeferredPrelaunchMessageDispatchesToRunControlTransport(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_control');
        $transport->reset();

        $message = new ContinueForkDeferredPrelaunchMessage(
            batchLifecycleId: 'batch-fork-prelaunch-route',
            forkLocalRunId: 'fork-local-run-route',
            terminalEventType: 'context_compacted',
            terminalPayload: ['messages_replaced' => true],
        );

        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('agent.command.bus');
        $commandBus->dispatch($message);

        $sent = $transport->getSent();
        $this->assertCount(1, $sent, 'Expected exactly one envelope on run_control after dispatch.');
        $this->assertSame($message, $sent[0]->getMessage());
    }
}
