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
 * Thesis: OM registers exact public command names om-status/om-view and permanent recall tool
 * whose model-facing metadata retains the faithful Pi decision/provenance/no-search guidance
 * plus the Hatfield 12..64 hex id schema (so future shortening fails this test).
 */
final class ObservationalMemoryExtensionRegistrationTest extends TestCase
{
    #[Test]
    public function registerExposesExactCommandsAndRecallTool(): void
    {
        $commands = [];
        $tools = [];
        $compactHooks = [];
        $api = new class($commands, $tools, $compactHooks) implements ExtensionApiInterface {
            /**
             * @param list<CommandDefinitionDTO>          $commands
             * @param list<ToolRegistrationDTO>           $tools
             * @param list<BeforeCompactionHookInterface> $compactHooks
             */
            public function __construct(
                private array &$commands,
                private array &$tools,
                private array &$compactHooks,
            ) {
            }

            public function getCwd(): string
            {
                return sys_get_temp_dir();
            }

            public function getSettings(string $key): array
            {
                return [
                    'model' => 'llama_cpp_test/test',
                    'observer' => [],
                    'reflector' => [],
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

            public function registerSkill(string $skillDirectory): void
            {
            }

            public function registerCommand(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
                $this->commands[] = $definition;
            }

            public function registerAfterTurnCommitHook(AfterTurnCommitHookInterface $hook): void
            {
            }

            public function registerSessionStartHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface $hook): void
            {
            }

            public function registerBeforeCompactionHook(BeforeCompactionHookInterface $hook): void
            {
                $this->compactHooks[] = $hook;
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

        $this->assertCount(1, $compactHooks);
        $this->assertInstanceOf(\Ineersa\HatfieldExt\ObservationalMemory\Compaction\OmBeforeCompactionHook::class, $compactHooks[0]);

        $names = array_map(static fn (CommandDefinitionDTO $d): string => $d->name, $commands);
        $this->assertSame(['om-status', 'om-view'], $names);
        $this->assertCount(1, $tools);
        $recall = $tools[0];
        $this->assertSame('recall', $recall->name);
        $this->assertSame('^[a-f0-9]{12,64}$', $recall->parametersJsonSchema['properties']['id']['pattern'] ?? null);

        $this->assertStringContainsString('Recover exact evidence and source context', $recall->description);
        $this->assertStringContainsString('current session', $recall->description);
        $this->assertStringNotContainsString('current branch', $recall->description);

        $idDescription = (string) ($recall->parametersJsonSchema['properties']['id']['description'] ?? '');
        $this->assertStringContainsString('12–64', $idDescription);
        $this->assertStringContainsString('does not search by topic', $idDescription);
        $this->assertStringContainsString('/om-view', $idDescription);

        $this->assertSame(
            'Use recall(<id>) to recover exact source context behind compacted memory observations/reflections when precision matters.',
            $recall->promptSummary,
        );

        $guidelines = implode("\n", $recall->promptGuidelines);
        $this->assertCount(5, $recall->promptGuidelines);
        $this->assertStringContainsString('exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance', $guidelines);
        $this->assertStringContainsString('supporting observations or raw sources', $guidelines);
        $this->assertStringContainsString('user asks why you believe something', $guidelines);
        $this->assertStringContainsString('semantic search or transcript browsing', $guidelines);
        $this->assertStringContainsString('unique lowercase 12–64 hex memory id', $guidelines);
        $this->assertStringContainsString('Do not recall every id preemptively', $guidelines);
        $this->assertStringNotContainsString('12-character memory id', $guidelines);
    }
}
