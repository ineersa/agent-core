<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeCallRequestDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\SqliteQueue\Client;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Worker;

/**
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge
 *
 * @requires OS Linux
 */
final class CodeModeHostBridgeTest extends IsolatedKernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-mode-bridge');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[\DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback]
    public function testBrokerLaunchReturnsBeforeItsNestedRequestIsHandled(): void
    {
        $container = self::getContainer();
        $entry = \dirname(__DIR__, 4).'/bin/console';
        $locator = $this->createStub(\Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator::class);
        $locator->method('path')->willReturn($entry);
        $locator->method('command')->willReturn([\PHP_BINARY, $entry]);
        $supervisor = new SessionSqliteQueueBrokerSupervisor(new NullLogger(), new RuntimeProcessConfig($locator, $this->tmpDir));
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir, 'code-mode-broker');
        $endpoint = 'HATFIELD_SQLITE_QUEUE_ENDPOINT';
        $previous = $_SERVER[$endpoint] ?? null;
        $runId = $this->prepareTopLevelRegistration('broker-launch');
        $owner = $container->get(CodeModeProcessOwner::class);
        $client = null;
        try {
            $supervisor->start($paths);
            $_SERVER[$endpoint] = $paths->endpointPath;
            $client = Client::connect($paths->endpointPath);
            $accessor = $container->get(StackToolExecutionContextAccessor::class);
            $context = new ToolContext($runId, 1, 'broker-launch', 'code_mode', new NullCancellationToken(), 5, 0, null, stepId: 'code-mode-bridge-test-step');
            $tool = $container->get(CodeModeTool::class);
            // Deliberately do not service the queue until the launcher has returned.
            $fixture = $this->tmpDir.'/nested-read.txt';
            file_put_contents($fixture, 'broker nested read');
            $otherFixture = $this->tmpDir.'/other-read.txt';
            file_put_contents($otherFixture, 'distinct second read');
            $contended = $this->tmpDir.'/contended';
            $script = 'try { tool("read", ["invalid list"]); } catch (RuntimeException $error) { $refusal = $error->getMessage(); }'
                .' try { tool("code_mode", ["script" => "return 42;"]); } catch (RuntimeException $error) { $recursion = $error->getMessage(); }'
                .' $first = \Amp\async(static fn () => tool("read", ["path" => '.var_export($fixture, true).']));'
                .' $second = \Amp\async(static function () {'
                .' \Revolt\EventLoop::queue(static fn () => touch('.var_export($contended, true).'));'
                .' return tool("read", ["path" => '.var_export($otherFixture, true).']); });'
                .' return [$refusal ?? null, $first->await(), $recursion ?? null, $second->await(), str_repeat("x", 600000)];';
            $outcome = $accessor->with($context, static fn (): mixed => $tool(new CodeModeArgumentsDTO($script, 5)));
            $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
            $this->assertTrue($client->wait('code_mode_requests', 3000));
            $transport = (new TransportFactory())->createTransport(
                $paths->transportDsns()['HATFIELD_CODE_MODE_REQUESTS_TRANSPORT_DSN'],
                [],
                $container->get('messenger.transport.symfony_serializer'),
            );
            $execution = $container->get('agent.execution.bus');
            $bus = new RoutableMessageBus(new ServiceLocator(['agent.execution.bus' => static fn () => $execution]), $execution);
            $consume = function (int $count) use ($transport, $bus): array {
                $events = new EventDispatcher();
                $handled = [];
                $worker = new Worker(['code_mode_requests' => $transport], $bus, $events, messageExecutionStrategy: new FiberMessageExecutionStrategy($bus, 1));
                $events->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use (&$handled, $worker, $count): void {
                    $handled[] = $event->getEnvelope()->getMessage();
                    if (\count($handled) === $count) {
                        $worker->stop();
                    }
                });
                $events->addListener(\Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class, static function ($event): void {
                    throw $event->getThrowable();
                });
                $deadline = hrtime(true) + 3_000_000_000;
                $events->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use ($deadline): void {
                    if (hrtime(true) >= $deadline) {
                        $event->getWorker()->stop();
                    } elseif ($event->isWorkerIdle()) {
                        \Amp\delay(0.01);
                    }
                });
                $worker->run(['fetch_size' => 1, 'sleep' => 0]);
                $this->assertCount($count, $handled);

                return $handled;
            };
            [, $invalidRequest, $outerRequest] = $consume(3);
            // Do not handle either read until both Fibers entered tool(). The
            // queued marker runs only once the second Fiber has yielded.
            $deadline = hrtime(true) + 3_000_000_000;
            while (!is_file($contended) && hrtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            $this->assertFileExists($contended);
            [$readRequest] = $consume(2);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $invalidRequest);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $outerRequest);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $readRequest);
            $this->assertSame($outcome->deferredId, $outerRequest->invocationId);
            $this->assertSame($outcome->deferredId, $readRequest->invocationId);
            $this->assertSame($outcome->deferredId, $readRequest->rootId);
            $record = $owner->invocation($runId, $readRequest->invocationId);
            $this->assertNotNull($record);
            $terminalTransport = $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory::class)->terminal($outcome->deferredId);
            $report = null;
            $deadline = hrtime(true) + 3_000_000_000;
            do {
                $terminal = $owner->observeTerminal($runId, $outcome->deferredId, null, null, static function () use ($terminalTransport, $container, &$report): ?array {
                    foreach ($terminalTransport->get() as $delivery) {
                        $message = $delivery->getMessage();
                        $terminalTransport->ack($delivery);

                        self::assertInstanceOf(\Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO::class, $message);
                        $report = $message;
                        $result = $container->get(\Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface::class)->read($message->payload);
                        self::assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ToolCallResult::class, $result);
                        self::assertIsArray($result->result);

                        return $result->result;
                    }

                    return null;
                });
                if (null !== $terminal) {
                    break;
                }
                \Amp\delay(0.01);
            } while (hrtime(true) < $deadline);
            $this->assertNotNull($report);
            // Requeue the same report to exercise the controller receiver independently
            // of the payload inspection above. A reserved row is still in the broker.
            $terminalTransport->send(new \Symfony\Component\Messenger\Envelope($report));
            $queue = 'code_mode_terminal.'.$outcome->deferredId;
            $brokerDb = new \PDO('sqlite:'.$paths->databasePath);
            $query = $brokerDb->prepare('SELECT reserved_until FROM queue_messages WHERE queue = ?');
            $reservation = static function () use ($query, $queue): mixed {
                $query->execute([$queue]);
                $value = $query->fetchColumn();
                $query->closeCursor();

                return $value;
            };
            $commandBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
            $canonical = null;
            $commandBus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$canonical, $reservation): \Symfony\Component\Messenger\Envelope {
                self::assertIsInt($reservation());
                self::assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ToolCallResult::class, $message);
                $canonical = $message;

                return new \Symfony\Component\Messenger\Envelope($message);
            });
            $deferred = $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class);
            $payloads = $container->get(\Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface::class);
            $observer = new \Ineersa\CodingAgent\Runtime\Controller\CodeModeTerminalObserver(
                $owner, $deferred, $commandBus, new NullLogger(), $container->get(\Ineersa\CodingAgent\Tool\OutputCap::class), $payloads,
                $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor::class),
                $container->get(\Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor::class),
                $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory::class),
            );
            $observer->observe($runId, $runId);
            $this->assertNotNull($canonical);
            $this->assertIsInt($reservation(), 'Dispatch alone must not acknowledge the terminal report.');
            $deferred->acceptResultReference($outcome->deferredId, $payloads->publish($canonical));
            $observer->observe($runId, $runId);
            $this->assertFalse($reservation(), 'Canonical admission allows acknowledgement.');
            $terminalTransport->close();
            $this->assertNotNull($terminal);
            $this->assertTrue($terminal['ok'], $terminal['error'] ?? 'Expected a successful nested read.');
            $this->assertSame('Invalid code-mode call request.', $terminal['result'][0] ?? null);
            $this->assertStringContainsString('broker nested read', $terminal['result'][1]);
            $this->assertStringContainsString('Tool "code_mode" is not supported', $terminal['result'][2]);
            $this->assertStringContainsString('distinct second read', $terminal['result'][3]);
            $this->assertSame(str_repeat('x', 600000), $terminal['result'][4]);
            $this->assertSame([$outcome->deferredId], $owner->controllerInvocationIds($runId));
        } finally {
            if (null === $previous) {
                unset($_SERVER[$endpoint]);
            } else {
                $_SERVER[$endpoint] = $previous;
            }
            $client?->close();
            $owner->cleanup($runId);
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testRefusesMissingSessionInvocation(): void
    {
        $tool = self::getContainer()->get(CodeModeTool::class);
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires a valid session invocation');
        $tool(new CodeModeArgumentsDTO('return 42;'));
    }

    public function testRefusesUnavailableBroker(): void
    {
        $container = self::getContainer();
        $id = 'broker-unavailable';
        $runId = 'broker-refusal-fixture';
        $tool = $container->get(CodeModeTool::class);
        $context = new ToolContext($runId, 1, $id, 'code_mode', new NullCancellationToken(), 5, 0, null, stepId: 'code-mode-bridge-test-step');
        $endpoint = 'HATFIELD_SQLITE_QUEUE_ENDPOINT';
        $previous = $_SERVER[$endpoint] ?? null;
        $_SERVER[$endpoint] = $this->tmpDir.'/missing.sock';
        try {
            $container->get(StackToolExecutionContextAccessor::class)->with($context, static fn () => $tool(new CodeModeArgumentsDTO('return 42;')));
            $this->fail('Expected broker refusal.');
        } catch (ToolCallException $error) {
            $this->assertStringContainsString('session broker unavailable', $error->getMessage());
            $this->assertFalse($error->retryable());
        } finally {
            if (null === $previous) {
                unset($_SERVER[$endpoint]);
            } else {
                $_SERVER[$endpoint] = $previous;
            }
        }
    }

    private function prepareTopLevelRegistration(string $toolCallId): string
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('code mode bridge '.$toolCallId);
        $call = new ExecuteToolCall($runId, 1, 'code-mode-bridge-test-step', 1, 'key-'.$toolCallId, $toolCallId, 'code_mode', [], 0);
        $batches = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        \Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration::register(
            new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector($batches),
            $batches,
            $runId,
            1,
            'code-mode-bridge-test-step',
            [$call],
        );
        $call = $batches->loadCall($runId, 1, 'code-mode-bridge-test-step', $toolCallId);
        $this->assertInstanceOf(ExecuteToolCall::class, $call);

        return $runId;
    }
}
