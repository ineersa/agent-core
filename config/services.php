<?php

declare(strict_types=1);

use Ineersa\AgentCore\Api\Http\RunApiController;
use Ineersa\AgentCore\Api\Http\RunReadService;
use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
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
use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Ineersa\AgentCore\Application\Handler\RunEventDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Orchestrator\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Orchestrator\AgentRunner;
use Ineersa\AgentCore\Application\Orchestrator\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Orchestrator\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Orchestrator\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Orchestrator\RunCommit;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageHandler;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageProcessor;
use Ineersa\AgentCore\Application\Orchestrator\RunMessageStateTools;
use Ineersa\AgentCore\Application\Orchestrator\RunOrchestrator;
use Ineersa\AgentCore\Application\Orchestrator\StartRunHandler;
use Ineersa\AgentCore\Application\Orchestrator\ToolCallResultHandler;
use Ineersa\AgentCore\Command\AgentLoopHealthCommand;
use Ineersa\AgentCore\Command\AgentLoopResumeStaleRunsCommand;
use Ineersa\AgentCore\Command\AgentLoopRunInspectCommand;
use Ineersa\AgentCore\Command\AgentLoopRunRebuildHotStateCommand;
use Ineersa\AgentCore\Command\AgentLoopRunReplayCommand;
use Ineersa\AgentCore\Command\AgentLoopRunTailCommand;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Api\AuthorizeRunInterface;
use Ineersa\AgentCore\Contract\ArtifactStoreInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Extension\CommandHandlerInterface;
use Ineersa\AgentCore\Contract\OutboxStoreInterface;
use Ineersa\AgentCore\Contract\Extension\EventSubscriberInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolIdempotencyKeyResolverInterface;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Mercure\RunTopicPolicy;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryOutboxStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunAccessStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\LocalArtifactStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Security\AllowAllAuthorizeRun;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogWriter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\BeforeProviderRequestSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Schema\CommandPayloadNormalizer;
use Ineersa\AgentCore\Schema\EventNameMap;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use League\Flysystem\Filesystem;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
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
        ->instanceof(RunMessageHandler::class)
        ->tag('agent_loop.orchestrator.message_handler')
    ;

    $services->set(AgentRunner::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;
    $services->alias(AgentRunnerInterface::class, AgentRunner::class);

    $services->set(EventNameMap::class);
    $services->set(EventPayloadNormalizer::class);
    $services->set(CommandPayloadNormalizer::class);
    $services->set(RunEventSerializer::class)
        ->arg('$eventPayloadNormalizer', service(EventPayloadNormalizer::class))
    ;
    $services->set(RunTopicPolicy::class);
    $services->set(RunReadService::class);

    $services->set(AllowAllAuthorizeRun::class);
    $services->alias(AuthorizeRunInterface::class, AllowAllAuthorizeRun::class);

    $services->set(RunCommit::class);

    $services->set(CommandMailboxPolicy::class)
        ->arg('$steerDrainMode', param('agent_loop.commands.steer_drain_mode'))
    ;

    $services->set(RunMessageStateTools::class);

    $services->set(StartRunHandler::class)
        ->arg('$commandBus', service('agent.command.bus'))
    ;

    $services->set(ApplyCommandHandler::class)
        ->arg('$maxPendingCommands', param('agent_loop.commands.max_pending_per_run'))
        ->arg('$commandBus', service('agent.command.bus'))
    ;

    $services->set(AdvanceRunHandler::class);

    $services->set(LlmStepResultHandler::class)
        ->arg('$toolbox', service(ToolboxInterface::class)->nullOnInvalid())
        ->arg('$commandBus', service('agent.command.bus'))
    ;

    $services->set(ToolCallResultHandler::class);

    $services->set(RunMessageProcessor::class)
        ->arg('$handlers', tagged_iterator('agent_loop.orchestrator.message_handler'))
    ;

    $services->set(RunOrchestrator::class);

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
    $services->set(RunMetrics::class);
    $services->set(RunTracer::class);

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

    $services->set(AgentMessageConverter::class);

    $services->set(DynamicToolDescriptionProcessor::class)
        ->arg('$toolbox', service(ToolboxInterface::class)->nullOnInvalid())
    ;

    $services->set(ModelResolverRoutingSubscriber::class)
        ->arg('$modelResolver', service(ModelResolverInterface::class)->nullOnInvalid())
    ;

    $services->set(BeforeProviderRequestSubscriber::class)
        ->arg('$hooks', tagged_iterator('agent_loop.hook.before_provider_request'))
    ;

    $services->set(LlmPlatformAdapter::class)
        ->arg('$platform', service('Symfony\\AI\\Platform\\PlatformInterface'))
        ->arg('$transformContextHooks', tagged_iterator('agent_loop.hook.transform_context'))
        ->arg('$convertToLlmHooks', tagged_iterator('agent_loop.hook.convert_to_llm'))
    ;
    $services->alias(PlatformInterface::class, LlmPlatformAdapter::class);

    $services->set(ToolExecutor::class)
        ->arg('$defaultMode', param('agent_loop.tools.defaults.mode'))
        ->arg('$defaultTimeoutSeconds', param('agent_loop.tools.defaults.timeout_seconds'))
        ->arg('$maxParallelism', param('agent_loop.tools.max_parallelism'))
        ->arg('$overrides', param('agent_loop.tools.overrides'))
        ->arg('$toolbox', service(ToolboxInterface::class)->nullOnInvalid())
        ->arg('$resultStore', service(ToolExecutionResultStore::class))
        ->arg('$toolIdempotencyKeyResolver', service(ToolIdempotencyKeyResolverInterface::class)->nullOnInvalid())
    ;
    $services->alias(ToolExecutorInterface::class, ToolExecutor::class);

    $services->set(ExecuteLlmStepWorker::class)
        ->arg('$commandBus', service('agent.command.bus'))
        ->arg('$defaultModel', param('agent_loop.llm.default_model'))
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
        ->arg('$eventPayloadNormalizer', service(EventPayloadNormalizer::class))
    ;

    $services->set(RunLogReader::class)
        ->arg('$filesystem', service('agent_loop.run_log.storage'))
        ->arg('$eventPayloadNormalizer', service(EventPayloadNormalizer::class))
    ;

    $services->set(RunEventStore::class);
    $services->alias(EventStoreInterface::class, RunEventStore::class);

    $services->set(InMemoryRunStore::class);
    $services->alias(RunStoreInterface::class, InMemoryRunStore::class);

    $services->set(InMemoryRunAccessStore::class);
    $services->alias(RunAccessStoreInterface::class, InMemoryRunAccessStore::class);

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
        ->arg('$serializer', service(RunEventSerializer::class))
        ->arg('$topicPolicy', service(RunTopicPolicy::class))
    ;

    $services->set(JsonlOutboxProjectorWorker::class);
    $services->set(MercureOutboxProjectorWorker::class);
    $services->set(ReplayService::class);
    $services->set(RunDebugService::class);

    $services->set(AgentLoopHealthCommand::class)
        ->arg('$config', param('agent_loop.config'))
        ->public()
        ->tag('console.command')
    ;

    $services->set(AgentLoopResumeStaleRunsCommand::class)
        ->arg('$commandBus', service('agent.command.bus'))
        ->arg('$staleAfterSeconds', param('agent_loop.commands.resume_stale_after_seconds'))
        ->public()
        ->tag('console.command')
    ;

    $services->set(AgentLoopRunInspectCommand::class)
        ->public()
        ->tag('console.command')
    ;

    $services->set(AgentLoopRunReplayCommand::class)
        ->public()
        ->tag('console.command')
    ;

    $services->set(AgentLoopRunRebuildHotStateCommand::class)
        ->public()
        ->tag('console.command')
    ;

    $services->set(AgentLoopRunTailCommand::class)
        ->public()
        ->tag('console.command')
    ;
};
