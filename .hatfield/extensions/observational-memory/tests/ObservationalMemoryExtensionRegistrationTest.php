<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

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
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: OM registers exact public command names om-status/om-view and permanent recall tool.
 */
final class ObservationalMemoryExtensionRegistrationTest extends TestCase
{
    #[Test]
    public function registerExposesExactCommandsAndRecallTool(): void
    {
        $commands = [];
        $tools = [];
        $api = new class($commands, $tools) implements ExtensionApiInterface {
            /** @param list<CommandDefinitionDTO> $commands
             * @param list<ToolRegistrationDTO> $tools */
            public function __construct(
                private array &$commands,
                private array &$tools,
            ) {
            }

            public function getCwd(): string
            {
                return sys_get_temp_dir();
            }

            public function getSettings(string $key): array
            {
                return [
                    'observer' => ['model' => 'llama_cpp_test/test'],
                    'reflector' => ['model' => 'llama_cpp_test/test'],
                ];
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

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
                $this->commands[] = $definition;
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
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

            public function exec(): ExecInterface
            {
                throw new \LogicException('unused');
            }
        };

        (new ObservationalMemoryExtension())->register($api);

        $names = array_map(static fn (CommandDefinitionDTO $d): string => $d->name, $commands);
        $this->assertSame(['om-status', 'om-view'], $names);
        $this->assertCount(1, $tools);
        $this->assertSame('recall', $tools[0]->name);
        $this->assertSame('^[a-f0-9]{64}$', $tools[0]->parametersJsonSchema['properties']['id']['pattern'] ?? null);
    }
}
