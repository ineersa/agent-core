<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Messenger;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Messenger\McpWorkerShutdownSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Test thesis 1: Subscriber calls disconnectAll(runId) when the stopping
 * worker includes the mcp transport and HATFIELD_SESSION_ID is set.
 *
 * Test thesis 2: Subscriber no-ops when the transport does not include
 * mcp, or when HATFIELD_SESSION_ID is empty or 'unknown'.
 */
class McpWorkerShutdownSubscriberTest extends TestCase
{
    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HATFIELD_SESSION_ID'], $_ENV['HATFIELD_SESSION_ID']);
        parent::tearDown();
    }

    /**
     * Worker with mcp transport → disconnectAll called with session ID.
     */
    public function testDisconnectAllCalledForMcpTransport(): void
    {
        $receiver = $this->createStub(ReceiverInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $worker = new Worker(['mcp' => $receiver], $bus);

        $event = new WorkerStoppedEvent($worker);

        /** @var McpConnectionManagerInterface&\PHPUnit\Framework\MockObject\MockObject $connectionManager */
        $connectionManager = $this->createMock(McpConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('disconnectAll')
            ->with('test-run-id');

        $_SERVER['HATFIELD_SESSION_ID'] = 'test-run-id';

        $subscriber = new McpWorkerShutdownSubscriber($connectionManager, $this->logger);
        $subscriber($event);
    }

    /**
     * Worker without mcp transport → no-op.
     */
    public function testNoOpForNonMcpTransport(): void
    {
        $receiver = $this->createStub(ReceiverInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $worker = new Worker(['tool' => $receiver], $bus);

        $event = new WorkerStoppedEvent($worker);

        /** @var McpConnectionManagerInterface&\PHPUnit\Framework\MockObject\MockObject $connectionManager */
        $connectionManager = $this->createMock(McpConnectionManagerInterface::class);
        $connectionManager->expects($this->never())
            ->method('disconnectAll');

        $_SERVER['HATFIELD_SESSION_ID'] = 'test-run-id';

        $subscriber = new McpWorkerShutdownSubscriber($connectionManager, $this->logger);
        $subscriber($event);
    }

    /**
     * No HATFIELD_SESSION_ID → logs warning, does not call disconnectAll.
     */
    public function testNoOpWhenSessionIdMissing(): void
    {
        $receiver = $this->createStub(ReceiverInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $worker = new Worker(['mcp' => $receiver], $bus);

        $event = new WorkerStoppedEvent($worker);

        /** @var McpConnectionManagerInterface&\PHPUnit\Framework\MockObject\MockObject $connectionManager */
        $connectionManager = $this->createMock(McpConnectionManagerInterface::class);
        $connectionManager->expects($this->never())
            ->method('disconnectAll');

        // Ensure no env var leaks from process
        unset($_SERVER['HATFIELD_SESSION_ID'], $_ENV['HATFIELD_SESSION_ID']);

        $subscriber = new McpWorkerShutdownSubscriber($connectionManager, $this->logger);
        $subscriber($event);

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame('worker.shutdown.no_session_id', $this->logger->records[0]['context']['mcp_event']);
    }

    /**
     * HATFIELD_SESSION_ID is 'unknown' → treated like missing, logs warning.
     */
    public function testNoOpWhenSessionIdIsUnknown(): void
    {
        $receiver = $this->createStub(ReceiverInterface::class);
        $bus = $this->createStub(MessageBusInterface::class);
        $worker = new Worker(['mcp' => $receiver], $bus);

        $event = new WorkerStoppedEvent($worker);

        /** @var McpConnectionManagerInterface&\PHPUnit\Framework\MockObject\MockObject $connectionManager */
        $connectionManager = $this->createMock(McpConnectionManagerInterface::class);
        $connectionManager->expects($this->never())
            ->method('disconnectAll');

        $_SERVER['HATFIELD_SESSION_ID'] = 'unknown';

        $subscriber = new McpWorkerShutdownSubscriber($connectionManager, $this->logger);
        $subscriber($event);

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame('worker.shutdown.no_session_id', $this->logger->records[0]['context']['mcp_event']);
    }
}
