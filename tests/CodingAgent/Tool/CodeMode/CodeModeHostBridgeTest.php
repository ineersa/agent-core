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
                    ], outputSchema: ['type' => 'object']),
                ]),
            ]));
            $registry = $container->get(\Ineersa\CodingAgent\Tool\ToolRegistry::class);
            $this->assertNotContains('fixture_echo', $registry->activeToolNames());
            $client = Client::connect($paths->endpointPath);
            $accessor = $container->get(StackToolExecutionContextAccessor::class);
            $context = new ToolContext($runId, 1, 'broker-launch', 'code_mode', new NullCancellationToken(), 5, 0, null, stepId: 'code-mode-bridge-test-step', retainOutput: true, attachmentOwnerToolCallId: 'attachment-owner');
            $tool = $container->get(CodeModeTool::class);
            // Deliberately do not service the queue until the launcher has returned.
            $fixture = $this->tmpDir.'/nested-read.txt';
            file_put_contents($fixture, 'broker nested read');
            $otherFixture = $this->tmpDir.'/other-read.txt';
            $nestedBytes = str_repeat('distinct retained read', 60000)."\n";
            file_put_contents($otherFixture, $nestedBytes);
            $contended = $this->tmpDir.'/contended';
            $shutdownReady = $this->tmpDir.'/shutdown-ready';
            $shutdownRelease = $this->tmpDir.'/shutdown-release';
            $this->assertTrue(posix_mkfifo($shutdownRelease, 0600));
            $script = 'try { $mcp = tool("fixture_echo", ["text" => "cold worker fixture"]); } catch (RuntimeException $error) { $mcp = $error->getMessage(); } try { tool("read", ["invalid list"]); } catch (RuntimeException $error) { $refusal = $error->getMessage(); }'
                .' $structured = tool("fixture_echo", ["text" => "structured-object"]);'
                .' try { tool("fixture_echo", ["text" => "structured-list"]); } catch (RuntimeException $error) { $schemaError = $error->getMessage(); }'
                .' try { tool("agent_cancel", ["artifact_id" => "unused"]); throw new LogicException("Nested agent_cancel was accepted"); } catch (RuntimeException $error) { if (!str_contains($error->getMessage(), "not supported inside code_mode")) { throw $error; } }'
                .' try { tool("code_mode", ["script" => "return 42;"]); } catch (RuntimeException $error) { $recursion = $error->getMessage(); }'
                .' $first = \Amp\async(static fn () => tool("read", ["path" => '.var_export($fixture, true).']));'
                .' $second = \Amp\async(static function () {'
                .' \Revolt\EventLoop::queue(static fn () => touch('.var_export($contended, true).'));'
                .' return tool("read", ["path" => '.var_export($otherFixture, true).']); });'
                .' register_shutdown_function(static function () { register_shutdown_function(static function () {'
                .' touch('.var_export($shutdownReady, true).'); file_get_contents('.var_export($shutdownRelease, true).'); echo "late-shutdown-diagnostics"; }); });'
                .' return [$refusal ?? null, $first->await(), $recursion ?? null, $second->await(), str_repeat("x", 1100000), $mcp, strlen($structured["data"]), $schemaError ?? null];';
            $container->get(\Ineersa\CodingAgent\Extension\ExtensionHookRegistry::class)->addToolCallRewriteHook('code_mode', new class implements \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface {
                public function rewriteArguments(\Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO $context): ?array
                {
                    return array_replace($context->arguments, ['timeout_seconds' => 30]);
                }
            });
            $toolbox = $container->get(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class);
            $outcome = $accessor->with($context, static fn () => $toolbox->execute(new \Symfony\AI\Platform\Result\ToolCall('broker-launch', 'code_mode', ['script' => $script, 'timeout_seconds' => 90]))->getResult());
            $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
            $this->assertTrue($client->wait('code_mode_requests', 3000));
            $newTransport = static fn () => (new TransportFactory())->createTransport(
                $paths->transportDsns()['HATFIELD_CODE_MODE_REQUESTS_TRANSPORT_DSN'],
                [],
                $container->get('messenger.transport.symfony_serializer'),
            );
            $execution = $container->get('agent.execution.bus');
            $bus = new RoutableMessageBus(new ServiceLocator(['agent.execution.bus' => static fn () => $execution]), $execution);
            $consume = function (int $count, array $receivers = []) use ($newTransport, $bus): array {
                $events = new EventDispatcher();
                $handled = [];
                $worker = new Worker($receivers + ['code_mode_requests' => $newTransport()], $bus, $events, messageExecutionStrategy: new FiberMessageExecutionStrategy($bus, 1));
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
            [, $mcpRequest, $invalidRequest, , , $outerRequest] = $consume(6);
            $this->assertSame('fixture_echo', $mcpRequest->name);
            $this->assertSame(['text' => 'cold worker fixture'], $mcpRequest->arguments);
            $this->assertSame('read', $invalidRequest->name);
            $this->assertSame(['invalid list'], $invalidRequest->arguments);
            $this->assertSame('code_mode', $outerRequest->name);
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
            $this->assertSame(['explicitTimeout' => 30, 'callerTimeout' => 5, 'retainOutput' => true, 'attachmentOwner' => 'attachment-owner'], $record['policy']);
            $this->assertSame($record['startedAtNs'] + 5_000_000_000, $owner->deadline($runId, $outcome->deferredId, false));
            $this->assertSame($record['startedAtNs'] + 30_000_000_000, $owner->deadline($runId, $outcome->deferredId, true));
            $deadline = hrtime(true) + 3_000_000_000;
            while (!is_file($shutdownReady) && hrtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            $this->assertFileExists($shutdownReady);
            $this->assertFalse($owner->hasExited($runId, $outcome->deferredId));
            $this->assertNull($client->receive('code_mode_requests'));
            file_put_contents($shutdownRelease, 'release');
            $this->assertTrue($client->wait('code_mode_requests', 3000), implode('', array_map(file_get_contents(...), glob($this->isolatedCwd().'/.hatfield/tmp/bg/*.log'))));
            $report = null;
            $transport = $newTransport();
            try {
                foreach ($transport->get() as $delivery) {
                    $report = $delivery->getMessage();
                    $transport->ack($delivery);
                    break;
                }
            } finally {
                $transport->close();
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
                $container->get(\Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface::class),
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
                $owner, $deferred, new NullLogger(),
                new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory(new TransportFactory(), $payloads, $container->get('messenger.transport.symfony_serializer'), $paths->endpointPath),
                $container->get(\Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface::class),
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
            $terminal = $payloads->read($payloads->findFirstResult(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalIdentity::forInvocation($runId, $outcome->deferredId)))->result;
            $this->assertNotNull($terminal);
            $this->assertTrue($terminal['ok'], $terminal['error'] ?? 'Expected a successful nested read.');
            $this->assertSame('Invalid code-mode call request.', $terminal['result'][0] ?? null);
            $this->assertStringContainsString('broker nested read', $terminal['result'][1]);
            $this->assertStringContainsString('Tool "code_mode" is not supported', $terminal['result'][2]);
            $nestedPath = $terminal['result'][3]['output_cap']['saved_path'];
            $container->get(\Ineersa\CodingAgent\Tool\OutputCap::class)->cleanupRun($runId, 'test');
            $this->assertSame(hash('sha256', $nestedBytes), hash_file('sha256', $nestedPath));
            $attachments = array_column($payloads->attachmentReferences($runId, 'attachment-owner'), 'path');
            $this->assertContains($nestedPath, $attachments);
            $this->assertTrue(array_any($attachments, static fn (string $path): bool => $path !== $nestedPath && str_contains((string) file_get_contents($path), str_repeat('x', 1100000)) && str_contains((string) file_get_contents($path), 'late-shutdown-diagnostics')));
            $this->assertSame(str_repeat('x', 1100000), $terminal['result'][4]);
            $this->assertStringContainsString('echo: cold worker fixture', json_encode($terminal['result'][5], \JSON_THROW_ON_ERROR));
            $this->assertSame(1_100_000, $terminal['result'][6]);
            $this->assertStringContainsString('does not match the declared output schema', $terminal['result'][7]);
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
            $replies = $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeStandaloneRepliesService::class);
            $receiver = $replies->open('tool-test');
            try {
                $standalone = \Amp\async(static fn () => $scheduler->handle($api, $job->payload, 'scheduled-occurrence', null));
                $consume(4, [\Ineersa\CodingAgent\Tool\CodeMode\CodeModeStandaloneRepliesService::RECEIVER => $receiver]);
                $standalone->await();
            } finally {
                $replies->close();
            }

            $previousSlot = getenv('HATFIELD_CODE_MODE_REPLY_SLOT');
            putenv('HATFIELD_CODE_MODE_REPLY_SLOT=extension_agent');
            try {
                $standalone = \Amp\async(static fn () => $invoker->invoke(new \Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationRequestDTO(
                    $runId, 'sequential-extension', 'code_mode', ['script' => $standaloneScript, 'timeout_seconds' => 5],
                )));
                $consume(3);
                $this->assertSame('42', trim($standalone->await(new \Amp\TimeoutCancellation(3))->text));
            } finally {
                putenv(false === $previousSlot ? 'HATFIELD_CODE_MODE_REPLY_SLOT' : 'HATFIELD_CODE_MODE_REPLY_SLOT='.$previousSlot);
            }

            // No waiting extension invocation is attached to this owned launch.
            $orphanWorkspace = $this->tmpDir.'/orphan-workspace';
            $orphanReady = $this->tmpDir.'/orphan-ready';
            $orphanRelease = $this->tmpDir.'/orphan-release';
            $this->assertTrue(posix_mkfifo($orphanRelease, 0600));
            $owner->launch($runId, 'orphan-standalone', $orphanWorkspace,
                'touch '.escapeshellarg($orphanReady).'; read -r line < '.escapeshellarg($orphanRelease),
                hrtime(true) - 2_000_000_000, ['explicitTimeout' => 1, 'callerTimeout' => null, 'retainOutput' => false, 'attachmentOwner' => 'orphan-standalone'],
                standaloneTurn: 0);
            $deadline = hrtime(true) + 3_000_000_000;
            while (!is_file($orphanReady) && hrtime(true) < $deadline) {
                \Amp\delay(0.01);
            }
            $this->assertFileExists($orphanReady);
            $observer->observe($runId, $runId);
            $observer->observe($runId, $runId);
            $this->assertTrue($owner->hasExited($runId, 'orphan-standalone'));
            [$timeoutWakeup] = $consume(1);
            $this->assertSame('orphan-standalone', $timeoutWakeup->invocationId);
            $this->assertStringContainsString('timed out', $timeoutWakeup->failure);

            $cancelledWorkspace = $this->tmpDir.'/cancelled-workspace';
            $owner->launch($runId, 'cancelled-standalone', $cancelledWorkspace,
                'read -r line < '.escapeshellarg($orphanRelease), hrtime(true),
                ['explicitTimeout' => null, 'callerTimeout' => null, 'retainOutput' => false, 'attachmentOwner' => 'cancelled-standalone'],
                standaloneTurn: 0);
            $status = $this->createStub(\Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface::class);
            $runStatus = \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelling;
            $status->method('findOperationalStatus')->willReturnCallback(static function () use (&$runStatus): \Ineersa\AgentCore\Contract\RunOperationalStatusDTO {
                return new \Ineersa\AgentCore\Contract\RunOperationalStatusDTO($runStatus, 0);
            });
            $cancellationObserver = new \Ineersa\CodingAgent\Runtime\Controller\CodeModeTerminalObserver(
                $owner, $deferred, new NullLogger(),
                new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory(new TransportFactory(), $payloads, $container->get('messenger.transport.symfony_serializer'), $paths->endpointPath),
                $status, $clock,
            );
            $cancellationObserver->observe($runId, $runId);
            $runStatus = \Ineersa\AgentCore\Domain\Run\RunStatus::Running;
            $cancellationObserver->observe($runId, $runId);
            $this->assertTrue($owner->hasExited($runId, 'cancelled-standalone'));
            $this->assertSame('cancelled', $owner->invocation($runId, 'cancelled-standalone')['interruption']);
            [$cancelWakeup] = $consume(1);
            $this->assertSame('cancelled-standalone', $cancelWakeup->invocationId);
            $this->assertStringContainsString('interrupted: cancelled', $cancelWakeup->failure);

            $toolReceiver = (new TransportFactory())->createTransport($paths->transportDsns()['HATFIELD_TOOL_TRANSPORT_DSN'], [], new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer());
            $toolReceiver->send(new \Symfony\Component\Messenger\Envelope((object) ['invocation' => 'shutdown-wait']));
            $wait = $container->get(\Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber::class);
            $replyService = new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeStandaloneRepliesService(
                new \Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory(new TransportFactory(), $payloads, $container->get('messenger.transport.symfony_serializer'), $paths->endpointPath),
                $payloads, $bus, $wait,
            );
            $entered = new \Amp\DeferredFuture();
            $shutdownBus = new class($replyService, $entered, $runId) implements \Symfony\Component\Messenger\MessageBusInterface {
                public function __construct(private \Ineersa\CodingAgent\Tool\CodeMode\CodeModeStandaloneRepliesService $replies, private \Amp\DeferredFuture $entered, private string $runId)
                {
                }

                public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
                {
                    $this->replies->register($this->runId, 'shutdown-wait');
                    $this->entered->complete();
                    try {
                        $this->replies->await($this->runId, 'shutdown-wait');
                    } finally {
                        $this->replies->unregister($this->runId, 'shutdown-wait');
                    }

                    return \Symfony\Component\Messenger\Envelope::wrap($message, $stamps);
                }
            };
            $events = new EventDispatcher();
            $failure = new \Amp\DeferredFuture();
            $events->addListener(\Symfony\Component\Messenger\Event\WorkerMessageFailedEvent::class, static fn ($event) => $failure->complete($event->getThrowable()));
            $command = new \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand(
                new RoutableMessageBus(new ServiceLocator([]), $shutdownBus),
                new ServiceLocator(['tool' => static fn () => $toolReceiver, 'agent' => $newTransport, 'code_mode_requests' => $newTransport]),
                $events, $container->get(\Ineersa\CodingAgent\Config\AppConfig::class), codeModeReplies: $replyService,
            );
            putenv('HATFIELD_CODE_MODE_REPLY_SLOT=tool-shutdown-test');
            $guard = \Revolt\EventLoop::delay(3, static fn () => throw new \RuntimeException('Shutdown drain did not release the pending reply.'));
            try {
                $input = new \Symfony\Component\Console\Input\ArrayInput(['--transport' => 'tool', '--memory-limit' => (string) (memory_get_usage(true) + 64 * 1024 * 1024)]);
                $input->bind($command->getDefinition());
                $stopping = \Amp\async(static function () use ($entered, $command): void {
                    $entered->getFuture()->await();
                    $command->handleSignal(\SIGTERM);
                });
                $this->assertSame(0, $command->run($input, new \Symfony\Component\Console\Output\BufferedOutput()));
                $stopping->await();
                $error = $failure->getFuture()->await(new \Amp\TimeoutCancellation(1));
                $this->assertInstanceOf(ToolCallException::class, $error);
                $this->assertSame('Code-mode reply receiver stopped.', $error->getMessage());
            } finally {
                \Revolt\EventLoop::cancel($guard);
                putenv(false === $previousSlot ? 'HATFIELD_CODE_MODE_REPLY_SLOT' : 'HATFIELD_CODE_MODE_REPLY_SLOT='.$previousSlot);
            }

            putenv('HATFIELD_CODE_MODE_REPLY_SLOT=extension_agent');
            $input = new \Symfony\Component\Console\Input\ArrayInput([]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $signal = new \Symfony\Component\Console\Event\ConsoleSignalEvent($command, $input, $output, \SIGTERM);
            $signalDispatcher = $container->get('event_dispatcher');
            $guard = \Revolt\EventLoop::delay(3, static fn () => throw new \RuntimeException('Scoped worker did not stop on the console signal.'));
            try {
                $replies->register($runId, 'scoped-shutdown');
                \Revolt\EventLoop::queue(static fn () => $signalDispatcher->dispatch($signal, \Symfony\Component\Console\ConsoleEvents::SIGNAL));
                try {
                    $replies->await($runId, 'scoped-shutdown');
                    $this->fail('Expected the scoped wait to fail on shutdown.');
                } catch (ToolCallException $error) {
                    $this->assertSame('Code-mode reply receiver stopped.', $error->getMessage());
                }
            } finally {
                $replies->unregister($runId, 'scoped-shutdown');
                \Revolt\EventLoop::cancel($guard);
                putenv(false === $previousSlot ? 'HATFIELD_CODE_MODE_REPLY_SLOT' : 'HATFIELD_CODE_MODE_REPLY_SLOT='.$previousSlot);
            }
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
        $call = new ExecuteToolCall($runId, 1, 'code-mode-bridge-test-step', 1, 'key-'.$toolCallId, $toolCallId, 'code_mode', ['timeout_seconds' => 90], 0);
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
