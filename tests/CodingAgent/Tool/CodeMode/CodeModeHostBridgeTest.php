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
use Symfony\Component\Messenger\Envelope;
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
            $script = 'echo tool("read", ["path" => '.var_export($fixture, true).']); echo str_repeat("X", 8192)."OMITTED_TAIL"; fwrite(STDERR, "child diagnostic"); throw new RuntimeException("child failed");';
            $script = 'try { tool("read", ["invalid list"]); } catch (RuntimeException $error) { $refusal = $error->getMessage(); }'
                .' try { tool("code_mode", ["script" => '.var_export($script, true).']); } catch (RuntimeException $error) { $child = $error->getMessage(); } return [$refusal ?? null, $child ?? null];';
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
            [$invalidRequest, $outerRequest, $readRequest] = $consume(3);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $invalidRequest);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $outerRequest);
            $this->assertInstanceOf(CodeModeCallRequestDTO::class, $readRequest);
            $this->assertSame($outcome->deferredId, $outerRequest->invocationId);
            $this->assertNotSame($outcome->deferredId, $readRequest->invocationId);
            $this->assertSame($outcome->deferredId, $readRequest->rootId);
            $record = $owner->invocation($runId, $readRequest->invocationId);
            $this->assertNotNull($record);
            $calls = $container->get(\Ineersa\CodingAgent\Entity\CodeModeCallRepository::class);
            $retained = $calls->reply($readRequest->invocationId, $readRequest->callId);
            $this->assertNotNull($retained);
            $this->assertSame('result', $retained->kind);
            $payload = $container->get(\Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface::class)->read($retained);
            $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ToolCallResult::class, $payload);
            $this->assertStringContainsString('broker nested read', json_encode($payload->result, \JSON_THROW_ON_ERROR));
            // Controlled worker turnover and redelivery of the exact request.
            file_put_contents($fixture, 'must not read the replacement bytes');
            $transport->send(new Envelope($readRequest));
            $consume(1);
            $this->assertEquals($retained, $calls->reply($readRequest->invocationId, $readRequest->callId));
            $observer = $container->get(\Ineersa\CodingAgent\Runtime\Controller\CodeModeTerminalObserver::class);
            // The controller reconciles the committed reply with native exit evidence.
            $deadline = hrtime(true) + 3_000_000_000;
            do {
                $observer?->observe($runId, $runId);
                $terminal = $owner->observeTerminal($runId, $outcome->deferredId, null, null);
                if (null !== $terminal) {
                    break;
                }
                \Amp\delay(0.01);
            } while (hrtime(true) < $deadline);
            $this->assertNotNull($terminal);
            $this->assertTrue($terminal['ok'], $terminal['error'] ?? 'Expected a successful nested read.');
            $this->assertSame('Invalid code-mode call request.', $terminal['result'][0] ?? null);
            $this->assertStringContainsString('broker nested read', $terminal['result'][1]);
            $this->assertStringContainsString('child failed', $terminal['result'][1]);
            $this->assertStringContainsString('child diagnostic', $terminal['result'][1]);
            $this->assertStringContainsString('[diagnostics truncated]', $terminal['result'][1]);
            $this->assertStringNotContainsString('OMITTED_TAIL', $terminal['result'][1]);
            $this->assertStringNotContainsString($record['workspace'], $terminal['result'][1]);
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
