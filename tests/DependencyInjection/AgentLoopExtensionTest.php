<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\DependencyInjection;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\JsonlOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\MercureOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Orchestrator\AgentRunner;
use Ineersa\AgentCore\Command\AgentLoopHealthCommand;
use Ineersa\AgentCore\Command\AgentLoopResumeStaleRunsCommand;
use Ineersa\AgentCore\Command\AgentLoopRunInspectCommand;
use Ineersa\AgentCore\Command\AgentLoopRunRebuildHotStateCommand;
use Ineersa\AgentCore\Command\AgentLoopRunReplayCommand;
use Ineersa\AgentCore\Command\AgentLoopRunTailCommand;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\DependencyInjection\AgentLoopExtension;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(AgentLoopExtension::class)]
final class AgentLoopExtensionTest extends TestCase
{
    public function testLoadRegistersCoreParametersAndServices(): void
    {
        $container = new ContainerBuilder();

        $extension = new AgentLoopExtension();
        $extension->load([[
            'llm' => [
                'default_model' => 'test-model',
            ],
        ]], $container);

        self::assertTrue($container->hasParameter('agent_loop.config'));
        self::assertSame('messenger', $container->getParameter('agent_loop.runtime'));
        self::assertSame('mercure', $container->getParameter('agent_loop.streaming'));
        self::assertSame('test-model', $container->getParameter('agent_loop.llm.default_model'));
        self::assertSame('agent_loop.run_logs', $container->getParameter('agent_loop.storage.run_log.flysystem_storage'));
        self::assertSame('one_at_a_time', $container->getParameter('agent_loop.commands.steer_drain_mode'));
        self::assertSame(120, $container->getParameter('agent_loop.commands.resume_stale_after_seconds'));

        self::assertTrue($container->hasDefinition(AgentRunner::class));
        self::assertTrue($container->hasDefinition(AgentLoopHealthCommand::class));
        self::assertTrue($container->hasDefinition(AgentLoopResumeStaleRunsCommand::class));
        self::assertTrue($container->hasDefinition(AgentLoopRunInspectCommand::class));
        self::assertTrue($container->hasDefinition(AgentLoopRunReplayCommand::class));
        self::assertTrue($container->hasDefinition(AgentLoopRunRebuildHotStateCommand::class));
        self::assertTrue($container->hasDefinition(AgentLoopRunTailCommand::class));
        self::assertTrue($container->hasDefinition('agent_loop.run_logs'));
        self::assertTrue($container->hasDefinition(RunLogReader::class));
        self::assertTrue($container->hasDefinition(HotPromptStateStore::class));
        self::assertTrue($container->hasDefinition(JsonlOutboxProjectorWorker::class));
        self::assertTrue($container->hasDefinition(MercureOutboxProjectorWorker::class));
        self::assertTrue($container->hasDefinition(ExecuteLlmStepWorker::class));
        self::assertTrue($container->hasDefinition(ExecuteToolCallWorker::class));
        self::assertTrue($container->hasDefinition(ReplayService::class));
        self::assertTrue($container->hasDefinition(RunDebugService::class));
        self::assertTrue($container->hasDefinition(MessageIdempotencyService::class));
        self::assertTrue($container->hasDefinition(RunLockManager::class));
        self::assertTrue($container->hasDefinition(RunMetrics::class));
        self::assertTrue($container->hasDefinition(RunTracer::class));
        self::assertTrue($container->hasDefinition(ToolBatchCollector::class));
        self::assertTrue($container->hasAlias(AgentRunnerInterface::class));
        self::assertSame(AgentRunner::class, (string) $container->getAlias(AgentRunnerInterface::class));
    }
}
