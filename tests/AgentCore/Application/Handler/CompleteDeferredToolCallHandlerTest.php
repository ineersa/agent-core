<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Serializer;

final class CompleteDeferredToolCallHandlerTest extends TestCase
{
    #[DataProvider('sendOutcomes')]
    public function testPendingCorrelationSettlesOnlyAfterNormalResultSendSucceeds(bool $sendSucceeds): void
    {
        $pending = true;
        $correlation = new DeferredToolCompletionCorrelation('deferred', 'run', 1, 'step', 1, 'key', 'call', 'echo', [], 0);
        $call = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'echo', [], 0);
        $result = new ToolCallResult('run', 1, 'step', 1, 'key', 'call', 0, ['tool_name' => 'echo', 'content' => []]);
        $invocation = new ToolPayloadReferenceDTO('call', 'run', 1, 'step', 1, 'key', 'call', 0, 'echo', str_repeat('a', 64), 10);
        $accepted = new ToolPayloadReferenceDTO('result', 'run', 1, 'step', 1, 'key', 'call', 0, 'echo', str_repeat('b', 64), 10);
        $repository = $this->createMock(DeferredToolCompletionRepositoryInterface::class);
        $repository->method('status')->willReturnCallback(static fn (): string => $pending ? 'pending' : 'completed');
        $repository->method('findByDeferredId')->willReturn($correlation);
        $repository->method('acknowledgedAt')->willReturn(null);
        $repository->method('findAcceptedResultReferenceByDeferredId')->willReturn(null);
        $repository->method('findInvocationReferenceByDeferredId')->willReturn($invocation);
        $repository->expects($this->once())->method('acceptResult')->willReturn($accepted);
        $repository->expects($sendSucceeds ? $this->once() : $this->never())->method('markCompleted')->with('deferred')->willReturnCallback(static function () use (&$pending): void {
            $pending = false;
        });
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($sendSucceeds ? $this->once() : $this->never())->method('delete')->with('run', 1, 'step');
        $payloads = $this->createStub(ToolPayloadStoreInterface::class);
        $payloads->method('read')->willReturnCallback(static fn (ToolPayloadReferenceDTO $reference): object => 'call' === $reference->kind ? $call : $result);
        $payloads->method('publish')->willReturn($accepted);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$pending, $sendSucceeds): Envelope {
            self::assertTrue($pending, 'The pending correlation survives through the send boundary.');
            if (!$sendSucceeds) {
                throw new \RuntimeException('transport unavailable');
            }

            return new Envelope($message);
        });
        $handler = new CompleteDeferredToolCallHandler(
            $repository,
            $bus,
            new RunLockManager(new LockFactory(new InMemoryStore())),
            $batches,
            $payloads,
            new DeferredToolInvocationFence(new Serializer()),
            new TestLogger(),
            null,
            null,
        );
        $handler(new CompleteDeferredToolCall('deferred', []));
        $this->assertSame(!$sendSucceeds, $pending);
    }

    public static function sendOutcomes(): iterable
    {
        yield 'successful send' => [true];
        yield 'failed send' => [false];
    }
}
