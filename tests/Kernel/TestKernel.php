<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Kernel;

use Ineersa\AgentCore\AgentLoopBundle;
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
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Orchestrator\AgentRunner;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $agentLoopConfig
     */
    public function __construct(
        private readonly array $agentLoopConfig = [],
        string $environment = 'test',
        bool $debug = true,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AgentLoopBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/agent-core/cache/'.$this->environment.'/'.substr(md5(json_encode($this->agentLoopConfig) ?: ''), 0, 8);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/agent-core/log/'.$this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        unset($loader, $builder);

        $container->extension('framework', [
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'messenger' => [
                'default_bus' => 'agent.command.bus',
            ],
        ]);

        $container->extension('agent_loop', $this->agentLoopConfig);

        $services = $container->services();

        foreach ([
            'test.agent_runner' => AgentRunner::class,
            'test.run_orchestrator' => RunOrchestrator::class,
            'test.run_reducer' => RunReducer::class,
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
            'test.tool_batch_collector' => ToolBatchCollector::class,
            'test.run_event_publisher' => RunEventPublisher::class,
            'test.command_router' => CommandRouter::class,
            'test.hook_dispatcher' => HookDispatcher::class,
            'test.run_event_dispatcher' => RunEventDispatcher::class,
        ] as $testAlias => $serviceId) {
            $services->alias($testAlias, $serviceId)
                ->public()
            ;
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        unset($routes);
        // Stage 00: no HTTP routes yet.
    }
}
