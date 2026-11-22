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
            $catalog = $container->get(\Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface::class);
            $catalog->write($runId, new \Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO(runId: $runId, servers: [
                'fixture' => new \Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO('fixture', 'stdio', \Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum::CONNECTED, tools: [
                    new \Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO('fixture_echo', 'fixture', 'echo', 'Echo fixture text', [
                        'type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'],
                    ]),
                ]),
            ]));
            $registry = $container->get(\Ineersa\CodingAgent\Tool\ToolRegistry::class);
            $this->assertNotContains('fixture_echo', $registry->activeToolNames());
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
            $script = 'try { $mcp = tool("fixture_echo", ["text" => "cold worker fixture"]); } catch (RuntimeException $error) { $mcp = $error->getMessage(); } try { tool("read", ["invalid list"]); } catch (RuntimeException $error) { $refusal = $error->getMessage(); }'
                .' try { tool("code_mode", ["script" => "return 42;"]); } catch (RuntimeException $error) { $recursion = $error->getMessage(); }'
                .' $first = \Amp\async(static fn () => tool("read", ["path" => '.var_export($fixture, true).']));'
                .' $second = \Amp\async(static function () {'
                .' \Revolt\EventLoop::queue(static fn () => touch('.var_export($contended, true).'));'
                .' return tool("read", ["path" => '.var_export($otherFixture, true).']); });'
                .' return [$refusal ?? null, $first->await(), $recursion ?? null, $second->await(), str_repeat("x", 600000), $mcp];';
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
            [, $mcpRequest, $invalidRequest, $outerRequest] = $consume(4);
            $this->assertSame('fixture_echo', $mcpRequest->name);
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
            $this->assertTrue($client->wait('code_mode_requests', 3000));
            $report = null;
            foreach ($transport->get() as $delivery) {
                $report = $delivery->getMessage();
                $transport->ack($delivery);
                break;
            }
            $this->assertInstanceOf(\Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO::class, $report);
            $payloads = $container->get(\Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface::class);
            $deferred = $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class);
            $replyFactory = $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory::class);
            $replyStore = $container->get(\Ineersa\CodingAgent\Session\SessionToolPayloadStore::class)->forWorkspaceReplies($runId, $record['workspace']);
            $this->assertSame([], glob($record['workspace'].'/replies/*'), 'The script has acknowledged and deleted all consumed bodies.');
            $root = $deferred->findMetadataByDeferredId($outcome->deferredId)->reference;
            $publish = static fn () => $owner->withReplyWorkspace($runId, $outcome->deferredId,
                static fn (string $workspace) => $replyFactory->publish($workspace, $root, ['ok' => true, 'result' => 42]));
            $unread = $publish();
            $replyFactory->send($outcome->deferredId, 'unread', $unread);
            $orphan = $publish(); // A persisted body whose send did not reach the broker.
            $this->assertSame(42, $replyStore->read($unread)->result['result']);
            $this->assertSame(42, $replyStore->read($orphan)->result['result']);
            $busFailure = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
            $busFailure->expects($this->once())->method('dispatch')->willReturnCallback(static function ($message) use ($payloads, $deferred, $outcome): never {
                $payloads->publishFirstResult($message, $deferred->findMetadataByDeferredId($outcome->deferredId)->reference);
                throw new \RuntimeException('Simulated send failure after retention');
            });
            $handler = new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalReportHandler(
                $owner, $deferred, $busFailure, $container->get(\Ineersa\CodingAgent\Tool\OutputCap::class), $payloads,
                $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor::class),
                $container->get(\Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor::class),
                $replyFactory,
            );
            try {
                $handler($report);
                $this->fail('Expected the simulated terminal dispatch failure');
            } catch (\RuntimeException $error) {
                $this->assertSame('Simulated send failure after retention', $error->getMessage());
            }
            $this->assertDirectoryDoesNotExist($record['workspace'].'/replies');
            $this->assertNull($publish(), 'An in-flight worker cannot recreate the retired reply scope.');
            $deadline = hrtime(true) + 3_000_000_000;
            while (!$owner->hasExited($runId, $outcome->deferredId) && hrtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            $this->assertTrue($owner->hasExited($runId, $outcome->deferredId));
            $clock = new \Symfony\Component\Clock\MockClock();
            $observer = new \Ineersa\CodingAgent\Runtime\Controller\CodeModeTerminalObserver(
                $owner, $deferred, new NullLogger(), $payloads, $handler,
                new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory(new TransportFactory(), $payloads, $container->get('messenger.transport.symfony_serializer'), $paths->endpointPath),
                $clock,
            );
            $observer->observe($runId, $runId);
            $clock->sleep(59);
            $observer->observe($runId, $runId);
            $this->assertNull($client->receive('code_mode_requests'));
            $clock->sleep(1);
            $observer->observe($runId, $runId);
            $observer->observe($runId, $runId);
            [$recovered] = $consume(1);
            $this->assertEquals($report, $recovered);
            $this->assertNull($client->receive('code_mode_requests'));
            $terminal = $payloads->read($report->payload)->result;
            $this->assertNotNull($terminal);
            $this->assertTrue($terminal['ok'], $terminal['error'] ?? 'Expected a successful nested read.');
            $this->assertSame('Invalid code-mode call request.', $terminal['result'][0] ?? null);
            $this->assertStringContainsString('broker nested read', $terminal['result'][1]);
            $this->assertStringContainsString('Tool "code_mode" is not supported', $terminal['result'][2]);
            $this->assertStringContainsString('distinct second read', $terminal['result'][3]);
            $this->assertSame(str_repeat('x', 600000), $terminal['result'][4]);
            $this->assertStringContainsString('echo: cold worker fixture', json_encode($terminal['result'][5], \JSON_THROW_ON_ERROR));
            $this->assertSame([$outcome->deferredId], $owner->controllerInvocationIds($runId));

            $invoker = new \Ineersa\CodingAgent\Extension\ExtensionToolInvoker(
                static fn () => $container->get(\Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface::class),
                $accessor, $container->get(\Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface::class),
                $container->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class),
                $container->get(\Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore::class),
                $payloads, $container->get(\Symfony\Component\Serializer\Normalizer\DenormalizerInterface::class),
                $runId, $container->get(\Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface::class),
            );
            $standaloneScript = 'if (!str_contains(tool("read", ["path" => '.var_export($fixture, true).']), "broker nested read")) { throw new RuntimeException("Unexpected read result"); } return 42;';
            $api = $this->createMock(\Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface::class);
            $api->method('getCwd')->willReturn($this->isolatedCwd());
            $api->method('tools')->willReturn($invoker);
            $scheduler = new \Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension();
            $scheduler->register($api);
            $scheduler->onAfterSessionStart(new \Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO($runId));
            $scheduler(['action' => 'create', 'tool' => 'code_mode', 'arguments' => ['script' => $standaloneScript, 'timeout_seconds' => 5], 'every' => '30 seconds'],
                new \Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO($runId));
            [$job] = iterator_to_array($scheduler->jobs($runId), false);
            $api->expects($this->once())->method('notifyOwner')->with($this->callback(static fn ($notice) => $notice->runId === $runId
                && 'schedule:scheduled-occurrence' === $notice->identity
                && 'Scheduled code_mode result ['.$job->payload['id']."]:\n42" === trim($notice->text)
                && !$notice->interrupted));
            $standalone = \Amp\async(static fn () => $scheduler->handle($api, $job->payload, 'scheduled-occurrence', null));
            $consume(3); // admission, nested read and terminal delivery on one shared worker slot
            $standalone->await();
        } finally {
            if (null === $previous) {
                unset($_SERVER[$endpoint]);
            } else {
                $_SERVER[$endpoint] = $previous;
            }
            $container->get(\Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface::class)->disconnectAll($runId);
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

    protected static function configureIsolatedProjectBeforeKernelBoot(string $projectDir): void
    {
        $directory = realpath($projectDir.'/.hatfield');
        if ($directory !== $projectDir.'/.hatfield' || file_exists($directory.'/mcp.json') || is_link($directory.'/mcp.json')) {
            throw new \LogicException('Expected a disposable, empty MCP fixture destination.');
        }
        (new \Symfony\Component\Filesystem\Filesystem())->dumpFile($directory.'/mcp.json', json_encode(['mcpServers' => [
            'fixture' => ['command' => \PHP_BINARY, 'args' => [__DIR__.'/../../Mcp/Fixtures/stdio-echo-server.php']],
        ]], \JSON_THROW_ON_ERROR));
    }

    private function prepareTopLevelRegistration(string $toolCallId): string
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('code mode bridge '.$toolCallId);
        self::getContainer()->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->replace(
            new \Ineersa\AgentCore\Domain\Run\RunState($runId, \Ineersa\AgentCore\Domain\Run\RunStatus::Running),
        );
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
