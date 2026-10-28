<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobDispatcher;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface;
use Ineersa\Hatfield\ExtensionApi\Session\OwnerNotificationDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\RegisteredToolInvokerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bridges public ExtensionApiInterface calls to the internal ToolRegistry.
 *
 * Extensions call registerTool(ToolRegistrationDTO) during their
 * HatfieldExtensionInterface::register() lifecycle. This bridge translates
 * each public DTO into a permanent tool registration on the CodingAgent
 * ToolRegistry, so extension-provided tools flow through the same active
 * tool set, provider schema exposure, execution allowlist, and prompt
 * metadata deduplication as built-in tools.
 *
 * This is an app-internal service. The ExtensionApi boundary remains pure:
 * ExtensionApi code (interfaces, DTOs) has no knowledge of ToolRegistry or
 * CodingAgent internals. The bridge sits in the AppExtension layer and is
 * the sole adapter between the two.
 */
final readonly class ExtensionToolRegistryBridge implements ExtensionApiInterface
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        private ExtensionHookRegistry $hookRegistry,
        private AppConfig $appConfig,
        private ExecInterface $execBridge,
        private CommandRegistryInterface $commandRegistry,
        private AgentRunnerInterface $agentRunner,
        private SessionEventReaderInterface $sessionEventReader,
        private ExtensionAgentJobRegistry $extensionAgentJobRegistry,
        private ExtensionAgentJobDispatcher $extensionAgentJobDispatcher,
        private StackToolExecutionContextAccessor $toolContextAccessor,
        private SkillDiscovery $skillDiscovery,
        private ExtensionToolInvoker $toolInvoker,
        private ChildRunExtensionAllowlistReaderInterface $children,
        private ExtensionRecurringJobRegistry $recurringJobs,
        #[Autowire(service: 'agent.command.bus')] private MessageBusInterface $commandBus,
    ) {
    }

    public function tools(): RegisteredToolInvokerInterface
    {
        return $this->toolInvoker;
    }

    public function notifyOwner(OwnerNotificationDTO $notification): void
    {
        $this->toolInvoker->assertOwner($notification->runId);
        $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO(
            $notification->runId, $notification->identity, $notification->text,
            origin: ['interrupted' => $notification->interrupted, 'retire_on_owner_cancel' => true],
        ));
    }

    public function registerRecurringJobSource(string $sourceId, RecurringJobSourceInterface $source): void
    {
        $this->recurringJobs->register($sourceId, $source);
    }

    /**
     * Register a permanent tool from an extension.
     *
     * Maps the public ToolRegistrationDTO fields to ToolRegistryInterface::registerTool()
     * signatures, deriving a default prompt line from name and description
     * when the DTO does not provide a promptSummary.
     *
     * ToolRegistry::registerTool() handles idempotent re-registration,
     * empty-name/description validation, and prompt metadata deduplication.
     *
     * @throws \InvalidArgumentException on empty name or description
     */
    public function registerTool(ToolRegistrationDTO $tool): void
    {
        // Accepts argument-only ExtensionToolHandlerInterface or contextual handlers.
        $adapter = new ExtensionToolHandlerAdapter($tool->handler, $this->toolContextAccessor, $this->children, $tool->mainOnly);

        $this->toolRegistry->registerTool(
            name: $tool->name,
            description: $tool->description,
            parametersJsonSchema: $tool->parametersJsonSchema,
            handler: $adapter,
            promptLine: $tool->promptSummary ?? \sprintf('%s: %s', $tool->name, $tool->description),
            promptGuidelines: $tool->promptGuidelines,
            timeoutSeconds: $tool->timeoutSeconds,
            extensionOwnerClass: ExtensionRegistrationContext::currentOwnerClass(),
            mainOnly: $tool->mainOnly,
        );
    }

    /**
     * Register a tool call hook.
     *
     * Hooks are stored in registration order on the shared ExtensionHookRegistry
     * so they can be iterated and dispatched during tool execution.
     */
    public function registerToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->hookRegistry->addToolCallHook($hook);
    }

    /**
     * Register a tool result hook.
     *
     * Hooks are stored in registration order on the shared ExtensionHookRegistry
     * so they can be iterated and dispatched after tool execution.
     */
    public function registerToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->hookRegistry->addToolResultHook($hook);
    }

    public function getSettings(string $key): array
    {
        $settings = $this->appConfig->extensions->settings[$key] ?? [];

        return \is_array($settings) ? $settings : [];
    }

    public function getCwd(): string
    {
        return $this->appConfig->cwd;
    }

    public function exec(): ExecInterface
    {
        return $this->execBridge;
    }

    public function registerPromptContributor(PromptContributorInterface $contributor): void
    {
        $this->hookRegistry->addPromptContributor($contributor);
    }

    public function registerSkill(string $skillDirectory): void
    {
        $this->skillDiscovery->registerSkill($skillDirectory);
    }

    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
        $this->commandRegistry->register($definition, $handler);
    }

    public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
        $this->hookRegistry->addToolCallRewriteHook($toolName, $hook);
    }

    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        $this->hookRegistry->addAfterTurnCommitHook($hook);
    }

    public function registerSessionStartHook(AfterSessionStartHookInterface $hook): void
    {
        $this->hookRegistry->addSessionStartHook($hook);
    }

    public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
    {
        $this->hookRegistry->addBeforeCompactionHook($hook);
    }

    public function agent(): AgentRunnerInterface
    {
        return $this->agentRunner;
    }

    public function sessionEvents(): SessionEventReaderInterface
    {
        return $this->sessionEventReader;
    }

    public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
    {
        $this->extensionAgentJobRegistry->register($handlerId, $handler);
    }

    public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
    {
        $this->extensionAgentJobDispatcher->dispatch($request);
    }
}
