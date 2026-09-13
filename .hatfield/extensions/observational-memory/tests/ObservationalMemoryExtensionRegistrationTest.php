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
 * Thesis: OM registers exact public command names om-status/om-view and permanent memory_search/recall tools
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
        $this->assertCount(2, $tools);
        $toolNames = array_map(static fn (ToolRegistrationDTO $t): string => $t->name, $tools);
        $this->assertSame(['memory_search', 'recall'], $toolNames);

        $search = $tools[0];
        $this->assertSame('query', ($search->parametersJsonSchema['required'] ?? [])[0] ?? null);
        $this->assertArrayHasKey('after', $search->parametersJsonSchema['properties'] ?? []);
        $this->assertArrayHasKey('before', $search->parametersJsonSchema['properties'] ?? []);
        $this->assertSame(50, $search->parametersJsonSchema['properties']['limit']['maximum'] ?? null);
        $this->assertStringContainsString('Find prior work', $search->description);
        $this->assertStringContainsString('Not semantic search', $search->description);
        $searchGuidelines = implode("\n", $search->promptGuidelines);
        $this->assertStringContainsString('prior work, conversations, PRs, issues', $searchGuidelines);
        $this->assertStringContainsString('all retained history', $searchGuidelines);
        $this->assertStringContainsString('No hits is not proof', $searchGuidelines);
        $this->assertStringContainsString('recall with that memory id and session_id', $searchGuidelines);

        $recall = $tools[1];
        $this->assertSame('recall', $recall->name);
        $this->assertSame('^[a-f0-9]{12,64}$', $recall->parametersJsonSchema['properties']['id']['pattern'] ?? null);
        $this->assertArrayHasKey('session_id', $recall->parametersJsonSchema['properties'] ?? []);

        $this->assertStringContainsString('Recover exact evidence and source context', $recall->description);
        $this->assertStringContainsString('current session', $recall->description);
        $this->assertStringContainsString('session_id', $recall->description);
        $this->assertStringNotContainsString('current branch', $recall->description);

        $idDescription = (string) ($recall->parametersJsonSchema['properties']['id']['description'] ?? '');
        $this->assertStringContainsString('12–64', $idDescription);
        $this->assertStringContainsString('Not a topic search', $idDescription);
        $this->assertStringContainsString('memory_search', $idDescription);

        $this->assertSame(
            'Use recall(id) or recall(id, session_id) to recover supporting evidence for a selected memory.',
            $recall->promptSummary,
        );

        $guidelines = implode("\n", $recall->promptGuidelines);
        $this->assertCount(5, $recall->promptGuidelines);
        $this->assertStringContainsString('selected relevant memory id', $guidelines);
        $this->assertStringContainsString('exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance', $guidelines);
        $this->assertStringContainsString('user asks why you believe something', $guidelines);
        $this->assertStringContainsString('semantic search or transcript browsing', $guidelines);
        $this->assertStringContainsString('verify current repo or PR state', $guidelines);
        $this->assertStringNotContainsString('12-character memory id', $guidelines);
    }
}
