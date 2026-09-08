<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests\Support;

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
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\StubExec;

final class TestExtensionApi implements ExtensionApiInterface
{
    /** @var list<ToolRegistrationDTO> */
    public array $tools = [];

    /** @var list<string> */
    public array $skills = [];

    /** @var list<PromptContributorInterface> */
    public array $prompts = [];

    /** @var list<CommandDefinitionDTO> */
    public array $commands = [];

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private string $cwd,
        private array $settings = [],
        private ?ExecInterface $exec = null,
    ) {
        $this->exec ??= new StubExec();
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function getSettings(string $key): array
    {
        $value = $this->settings[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    public function exec(): ExecInterface
    {
        return $this->exec ?? new StubExec();
    }

    public function registerTool(ToolRegistrationDTO $tool): void
    {
        $this->tools[] = $tool;
    }

    public function registerToolCallHook(ToolCallHookInterface $hook): void
    {
    }

    public function registerToolResultHook(ToolResultHookInterface $hook): void
    {
    }

    public function registerToolCallRewriteHook(string $toolName, ToolCallRewriteHookInterface $hook): void
    {
    }

    public function registerPromptContributor(PromptContributorInterface $contributor): void
    {
        $this->prompts[] = $contributor;
    }

    public function registerSkill(string $skillDirectory): void
    {
        $this->skills[] = $skillDirectory;
    }

    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
        $this->commands[] = $definition;
    }

    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
    }

    public function registerSessionStartHook(AfterSessionStartHookInterface $hook): void
    {
    }

    public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
    {
    }

    public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
    {
    }

    public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
    {
    }

    public function agent(): AgentRunnerInterface
    {
        throw new \LogicException('unused');
    }

    public function sessionEvents(): SessionEventReaderInterface
    {
        throw new \LogicException('unused');
    }
}
