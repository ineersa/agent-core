<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Claude;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
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
use Ineersa\HatfieldExt\TaskWorkflow\Exception\UnsupportedHostCapabilityException;

/**
 * Minimal Extension API host used to run task-workflow outside Hatfield.
 *
 * It records what the extension registers (tools, commands, prompt
 * contributors, skills) so the Claude Code MCP bridge can expose them. Host
 * capabilities task-workflow does not use fail loudly instead of degrading.
 */
final class ClaudeExtensionHost implements ExtensionApiInterface
{
    /** @var list<ToolRegistrationDTO> */
    private array $tools = [];

    /** @var list<array{definition: CommandDefinitionDTO, handler: ExtensionCommandHandlerInterface}> */
    private array $commands = [];

    /** @var list<PromptContributorInterface> */
    private array $promptContributors = [];

    /**
     * @param array<string, array<string, mixed>> $settings Extension settings keyed like Hatfield's `extensions.settings`
     */
    public function __construct(
        private readonly string $cwd,
        private readonly array $settings,
        private readonly ExecInterface $exec,
    ) {
    }

    /** @return list<ToolRegistrationDTO> */
    public function registeredTools(): array
    {
        return $this->tools;
    }

    /** @return list<array{definition: CommandDefinitionDTO, handler: ExtensionCommandHandlerInterface}> */
    public function registeredCommands(): array
    {
        return $this->commands;
    }

    public function instructions(): string
    {
        return trim(implode("\n\n", array_map(static fn (PromptContributorInterface $contributor): string => trim($contributor->contribute()), $this->promptContributors)));
    }

    public function registerTool(ToolRegistrationDTO $tool): void
    {
        $this->tools[] = $tool;
    }

    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
        $this->commands[] = ['definition' => $definition, 'handler' => $handler];
    }

    public function registerPromptContributor(PromptContributorInterface $contributor): void
    {
        $this->promptContributors[] = $contributor;
    }

    public function registerSkill(string $skillDirectory): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function getSettings(string $key): array
    {
        return $this->settings[$key] ?? [];
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function exec(): ExecInterface
    {
        return $this->exec;
    }

    public function tools(): RegisteredToolInvokerInterface
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function notifyOwner(OwnerNotificationDTO $notification): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerRecurringJobSource(string $sourceId, RecurringJobSourceInterface $source): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerToolCallHook(ToolCallHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerToolResultHook(ToolResultHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerSessionStartHook(AfterSessionStartHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function agent(): AgentRunnerInterface
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function isChildRun(string $runId): bool
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function sessionEvents(): SessionEventReaderInterface
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }

    public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
    {
        throw UnsupportedHostCapabilityException::for(__FUNCTION__);
    }
}
