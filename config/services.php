<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\EventSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\JsonlOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MercureOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunEventDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCatalogResolver;
use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Orchestrator\AgentRunner;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Command\AgentLoopHealthCommand;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\ArtifactStoreInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;
use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Contract\Extension\EventSubscriberInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCatalogProviderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolIdempotencyKeyResolverInterface;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\LocalArtifactStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Platform;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyMessageMapper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyPlatformInvoker;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyToolExecutorAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
    ;

    $services
        ->instanceof(CommandHandlerInterface::class)
        ->tag('agent_loop.extension.command_handler')
    ;

    $services
        ->instanceof(HookSubscriberInterface::class)
        ->tag('agent_loop.extension.hook_subscriber')
    ;

    $services
        ->instanceof(EventSubscriberInterface::class)
        ->tag('agent_loop.extension.event_subscriber')
    ;

    $services
        ->instanceof(TransformContextHookInterface::class)
        ->tag('agent_loop.hook.transform_context')
    ;

    $services
        ->instanceof(ConvertToLlmHookInterface::class)
        ->tag('agent_loop.hook.convert_to_llm')
    ;

    $services
        ->instanceof(BeforeProviderRequestHookInterface::class)
        ->tag('agent_loop.hook.before_provider_request')
    ;

    $services
        ->instanceof(BeforeToolCallHookInterface::class)
        ->tag('agent_loop.hook.before_tool_call')
    ;

    $services
        ->instanceof(AfterToolCallHookInterface::class)
        ->tag('agent_loop.hook.after_tool_call')
    ;

    $services
        ->instanceof(ToolCatalogProviderInterface::class)
        ->tag('agent_loop.tool_catalog_provider')
    ;

    $services->set(AgentRunner::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;
    $services->alias(AgentRunnerInterface::class, AgentRunner::class);

    $services->set(RunOrchestrator::class);
    $services->set(RunReducer::class);

    $services->set(StepDispatcher::class)
        ->arg('$executionBus', service('agent.execution.bus'))
        ->arg('$publisherBus', service('agent.publisher.bus'))
    ;

    $services->set('agent_loop.lock.store', InMemoryStore::class);

    $services->set('agent_loop.lock.factory', LockFactory::class)
        ->arg('$store', service('agent_loop.lock.store'))
    ;

    $services->set(RunLockManager::class)
        ->arg('$lockFactory', service('agent_loop.lock.factory'))
    ;

    $services->set(MessageIdempotencyService::class);

    $services->set(ToolExecutionPolicyResolver::class)
        ->arg('$defaultMode', param('agent_loop.tools.defaults.mode'))
        ->arg('$defaultTimeoutSeconds', param('agent_loop.tools.defaults.timeout_seconds'))
        ->arg('$maxParallelism', param('agent_loop.tools.max_parallelism'))
        ->arg('$overrides', param('agent_loop.tools.overrides'))
    ;

    $services->set(ToolExecutionResultStore::class);

    $services->set(ToolBatchCollector::class)
        ->arg('$defaultMaxParallelism', param('agent_loop.tools.max_parallelism'))
    ;

    $services->set(CommandHandlerRegistry::class)
        ->arg('$handlers', tagged_iterator('agent_loop.extension.command_handler'))
    ;

    $services->set(HookSubscriberRegistry::class)
        ->arg('$subscribers', tagged_iterator('agent_loop.extension.hook_subscriber'))
    ;

    $services->set(EventSubscriberRegistry::class)
        ->arg('$subscribers', tagged_iterator('agent_loop.extension.event_subscriber'))
    ;

    $services->set(CommandRouter::class)
        ->arg('$extensionPrefix', param('agent_loop.commands.custom_kind_prefix'))
    ;

    $services->set(HookDispatcher::class);
    $services->set(RunEventDispatcher::class);

    $services->set(ToolCatalogResolver::class)
        ->arg('$providers', tagged_iterator('agent_loop.tool_catalog_provider'))
    ;

    $services->set(SymfonyMessageMapper::class);

    $services->set(SymfonyPlatformInvoker::class)
        ->arg('$platform', service('Symfony\\AI\\Platform\\PlatformInterface')->nullOnInvalid())
    ;

    $services->set(Platform::class)
        ->arg('$transformContextHooks', tagged_iterator('agent_loop.hook.transform_context'))
        ->arg('$convertToLlmHooks', tagged_iterator('agent_loop.hook.convert_to_llm'))
        ->arg('$beforeProviderRequestHooks', tagged_iterator('agent_loop.hook.before_provider_request'))
        ->arg('$defaultModel', param('agent_loop.llm.default_model'))
    ;
    $services->alias(PlatformInterface::class, Platform::class);

    $services->set(ToolExecutor::class)
        ->arg('$defaultMode', param('agent_loop.tools.defaults.mode'))
        ->arg('$defaultTimeoutSeconds', param('agent_loop.tools.defaults.timeout_seconds'))
        ->arg('$maxParallelism', param('agent_loop.tools.max_parallelism'))
        ->arg('$overrides', param('agent_loop.tools.overrides'))
        ->arg('$toolbox', service('Symfony\\AI\\Agent\\Toolbox\\ToolboxInterface')->nullOnInvalid())
        ->arg('$beforeToolCallHooks', tagged_iterator('agent_loop.hook.before_tool_call'))
        ->arg('$afterToolCallHooks', tagged_iterator('agent_loop.hook.after_tool_call'))
        ->arg('$resultStore', service(ToolExecutionResultStore::class))
        ->arg('$toolIdempotencyKeyResolver', service(ToolIdempotencyKeyResolverInterface::class)->nullOnInvalid())
    ;

    $services->set(SymfonyToolExecutorAdapter::class)
        ->arg('$fallbackExecutor', service(ToolExecutor::class))
        ->arg('$toolbox', service('Symfony\\AI\\Agent\\Toolbox\\ToolboxInterface')->nullOnInvalid())
        ->arg('$beforeToolCallHooks', tagged_iterator('agent_loop.hook.before_tool_call'))
        ->arg('$afterToolCallHooks', tagged_iterator('agent_loop.hook.after_tool_call'))
    ;
    $services->alias(ToolExecutorInterface::class, ToolExecutor::class);

    $services->set(ExecuteLlmStepWorker::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;

    $services->set(ExecuteToolCallWorker::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;

    $services->set('agent_loop.run_logs.adapter.local', LocalFilesystemAdapter::class)
        ->arg('$location', param('agent_loop.storage.run_log.base_path'))
    ;

    $services->set('agent_loop.run_logs', Filesystem::class)
        ->arg('$adapter', service('agent_loop.run_logs.adapter.local'))
    ;

    $services->set(RunLogWriter::class)
        ->arg('$filesystem', service('agent_loop.run_log.storage'))
    ;

    $services->set(RunLogReader::class)
        ->arg('$filesystem', service('agent_loop.run_log.storage'))
    ;

    $services->set(RunEventStore::class);
    $services->alias(EventStoreInterface::class, RunEventStore::class);

    $services->set(InMemoryRunStore::class);
    $services->alias(RunStoreInterface::class, InMemoryRunStore::class);

    $services->set(InMemoryCommandStore::class);
    $services->alias(CommandStoreInterface::class, InMemoryCommandStore::class);

    $services->set(InMemoryPromptStateStore::class);
    $services->set(HotPromptStateStore::class);
    $services->alias(PromptStateStoreInterface::class, HotPromptStateStore::class);

    $services->set(InMemoryOutboxStore::class);
    $services->alias(OutboxStoreInterface::class, InMemoryOutboxStore::class);

    $services->set(LocalArtifactStore::class)
        ->arg('$basePath', param('agent_loop.storage.run_log.base_path'))
    ;
    $services->alias(ArtifactStoreInterface::class, LocalArtifactStore::class);

    $services->set(OutboxProjector::class);

    $services->set(RunEventPublisher::class)
        ->arg('$hub', service('mercure.hub.default')->nullOnInvalid())
    ;

    $services->set(JsonlOutboxProjectorWorker::class);
    $services->set(MercureOutboxProjectorWorker::class);
    $services->set(ReplayService::class);

    $services->set(AgentLoopHealthCommand::class)
        ->arg('$config', param('agent_loop.config'))
        ->public()
        ->tag('console.command')
    ;
};
