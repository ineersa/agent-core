<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Integration;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\JsonlOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MercureOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunEventDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Orchestrator\AgentRunner;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Command\AgentLoopHealthCommand;
use Ineersa\AgentCore\Command\AgentLoopResumeStaleRunsCommand;
use Ineersa\AgentCore\Command\AgentLoopRunInspectCommand;
use Ineersa\AgentCore\Command\AgentLoopRunRebuildHotStateCommand;
use Ineersa\AgentCore\Command\AgentLoopRunReplayCommand;
use Ineersa\AgentCore\Command\AgentLoopRunTailCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use Ineersa\AgentCore\Tests\Kernel\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

#[CoversNothing]
final class KernelIntegrationTest extends TestCase
{
    private static ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new TestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testBundleBootsInSymfonyKernel(): void
    {
        self::assertNotNull(self::$kernel);
        self::assertInstanceOf(ContainerInterface::class, self::$kernel->getContainer());
        self::assertArrayHasKey('AgentLoopBundle', self::$kernel->getBundles());
    }

    public function testCoreServicesAreVisibleInContainer(): void
    {
        $container = $this->serviceContainer();

        $expectations = [
            'test.agent_runner' => AgentRunner::class,
            'test.run_orchestrator' => RunOrchestrator::class,
            'test.step_dispatcher' => StepDispatcher::class,
            'test.tool_executor' => ToolExecutor::class,
            'test.run_log_writer' => RunLogWriter::class,
            'test.run_log_reader' => RunLogReader::class,
            'test.run_event_store' => RunEventStore::class,
            'test.hot_prompt_store' => HotPromptStateStore::class,
            'test.outbox_store' => InMemoryOutboxStore::class,
            'test.outbox_projector' => OutboxProjector::class,
            'test.jsonl_outbox_worker' => JsonlOutboxProjectorWorker::class,
            'test.mercure_outbox_worker' => MercureOutboxProjectorWorker::class,
            'test.execute_llm_worker' => ExecuteLlmStepWorker::class,
            'test.execute_tool_worker' => ExecuteToolCallWorker::class,
            'test.replay_service' => ReplayService::class,
            'test.message_idempotency_service' => MessageIdempotencyService::class,
            'test.run_lock_manager' => RunLockManager::class,
            'test.run_metrics' => RunMetrics::class,
            'test.run_tracer' => RunTracer::class,
            'test.tool_batch_collector' => ToolBatchCollector::class,
            'test.run_event_publisher' => RunEventPublisher::class,
            'test.command_router' => CommandRouter::class,
            'test.hook_dispatcher' => HookDispatcher::class,
            'test.run_event_dispatcher' => RunEventDispatcher::class,
        ];

        foreach ($expectations as $serviceId => $className) {
            self::assertTrue($container->has($serviceId), sprintf('Expected service alias "%s" in test container.', $serviceId));
            self::assertInstanceOf($className, $container->get($serviceId));
        }

        self::assertTrue($container->has(AgentLoopHealthCommand::class));
        self::assertTrue($container->has(AgentLoopResumeStaleRunsCommand::class));
        self::assertTrue($container->has(AgentLoopRunInspectCommand::class));
        self::assertTrue($container->has(AgentLoopRunReplayCommand::class));
        self::assertTrue($container->has(AgentLoopRunRebuildHotStateCommand::class));
        self::assertTrue($container->has(AgentLoopRunTailCommand::class));
        self::assertTrue($container->has('agent.command.bus'));
        self::assertTrue($container->has('agent.execution.bus'));
        self::assertTrue($container->has('agent.publisher.bus'));
    }

    public function testMessagesRouteToDifferentConfiguredTransportsInKernel(): void
    {
        $container = $this->serviceContainer();

        $commandBus = $container->get('agent.command.bus');
        $executionBus = $container->get('agent.execution.bus');

        self::assertInstanceOf(MessageBusInterface::class, $commandBus);
        self::assertInstanceOf(MessageBusInterface::class, $executionBus);

        $commandTransport = $container->get('messenger.transport.agent_loop.command');
        $executionTransport = $container->get('messenger.transport.agent_loop.execution');

        self::assertInstanceOf(InMemoryTransport::class, $commandTransport);
        self::assertInstanceOf(InMemoryTransport::class, $executionTransport);

        $commandTransport->reset();
        $executionTransport->reset();

        $runId = 'run-stage-00';

        try {
            $commandBus->dispatch(new StartRun(
                runId: $runId,
                turnNo: 0,
                stepId: 'start-step-1',
                attempt: 1,
                idempotencyKey: 'idemp-start-1',
                payload: new StartRunPayload(
                    systemPrompt: 'test',
                    messages: [],
                    metadata: new RunMetadata(),
                ),
            ));

            $executionBus->dispatch(new ExecuteLlmStep(
                runId: $runId,
                turnNo: 1,
                stepId: 'llm-step-1',
                attempt: 1,
                idempotencyKey: 'idemp-llm-1',
                contextRef: 'hot:run:run-stage-00',
                toolsRef: 'toolset:run:run-stage-00:turn:1',
            ));
        } catch (Throwable $exception) {
            self::fail(sprintf('Dispatch failed in kernel integration test: %s', $exception->getMessage()));
        }

        self::assertCount(1, $commandTransport->getSent(), 'StartRun should be sent to command transport.');
        self::assertCount(1, $executionTransport->getSent(), 'ExecuteLlmStep should be sent to execution transport.');

        self::assertInstanceOf(StartRun::class, $commandTransport->getSent()[0]->getMessage());
        self::assertInstanceOf(ExecuteLlmStep::class, $executionTransport->getSent()[0]->getMessage());
    }

    private function serviceContainer(): ContainerInterface
    {
        self::assertNotNull(self::$kernel);

        $container = self::$kernel->getContainer();

        if ($container->has('test.service_container')) {
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);

            return $testContainer;
        }

        return $container;
    }
}
