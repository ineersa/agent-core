<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests\Support;

use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;

final class TestExtensionApi implements ExtensionApiInterface
{
    /** @var list<ToolRegistrationDTO> */
    public array $tools = [];

    /** @var list<AfterTurnCommitHookInterface> */
    public array $afterTurnHooks = [];

    /** @var array<string, ExtensionAgentJobHandlerInterface> */
    public array $handlers = [];

    /** @var list<ExtensionAgentJobRequestDTO> */
    public array $jobs = [];

    public function __construct(
        private string $cwd,
        private ExecInterface $exec,
    ) {
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function getSettings(string $key): array
    {
        return [];
    }

    public function exec(): ExecInterface
    {
        return $this->exec;
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
    }

    public function registerSkill(string $skillDirectory): void
    {
    }

    public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
    {
    }

    public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
    {
        $this->afterTurnHooks[] = $hook;
    }

    public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
    {
    }

    public function registerExtensionAgentJobHandler(string $handlerId, ExtensionAgentJobHandlerInterface $handler): void
    {
        $this->handlers[$handlerId] = $handler;
    }

    public function dispatchExtensionAgentJob(ExtensionAgentJobRequestDTO $request): void
    {
        $this->jobs[] = $request;
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
