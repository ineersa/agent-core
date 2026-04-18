<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\EventSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Application\Handler\JsonlOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\MercureOutboxProjectorWorker;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunEventDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
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
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
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
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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

    $services->set(AgentRunner::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;
    $services->alias(AgentRunnerInterface::class, AgentRunner::class);

    $services->set(RunOrchestrator::class)
        ->arg('$runLogFilesystem', service('agent_loop.run_log.storage'))
    ;
    $services->set(RunReducer::class);

    $services->set(StepDispatcher::class)
        ->arg('$executionBus', service('agent.execution.bus'))
        ->arg('$publisherBus', service('agent.publisher.bus'))
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

    $services->set(Platform::class);
    $services->alias(PlatformInterface::class, Platform::class);

    $services->set(ToolExecutor::class)
        ->arg('$defaultMode', param('agent_loop.tools.defaults.mode'))
        ->arg('$defaultTimeoutSeconds', param('agent_loop.tools.defaults.timeout_seconds'))
        ->arg('$maxParallelism', param('agent_loop.tools.max_parallelism'))
        ->arg('$overrides', param('agent_loop.tools.overrides'))
    ;
    $services->alias(ToolExecutorInterface::class, ToolExecutor::class);

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
