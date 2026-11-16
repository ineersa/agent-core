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
 * whose compact model-facing metadata retains decision/provenance/no-search guidance
 * and the Hatfield 12..64 hex id schema.
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
            public function tools(): \Ineersa\Hatfield\ExtensionApi\Tool\RegisteredToolInvokerInterface
            {
                throw new \LogicException('Unused fixture capability.');
            }

            public function notifyOwner(\Ineersa\Hatfield\ExtensionApi\Session\OwnerNotificationDTO $notification): void
            {
                throw new \LogicException('Unused fixture capability.');
            }

            public function registerRecurringJobSource(string $sourceId, \Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobSourceInterface $source): void
            {
                throw new \LogicException('Unused fixture capability.');
            }

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

            public function isChildRun(string $runId): bool
            {
                return false;
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
        $this->assertTrue($search->mainOnly);
        $this->assertSame('query', ($search->parametersJsonSchema['required'] ?? [])[0] ?? null);
        $this->assertArrayHasKey('after', $search->parametersJsonSchema['properties'] ?? []);
        $this->assertArrayHasKey('before', $search->parametersJsonSchema['properties'] ?? []);
        $this->assertSame(50, $search->parametersJsonSchema['properties']['limit']['maximum'] ?? null);
        $this->assertStringContainsString('Find prior work', $search->description);
        $this->assertStringContainsString('Not semantic search', $search->description);
        $this->assertSame('memory_search query [after] [before] [limit=20] — search retained memories across sessions', $search->promptSummary);
        $searchGuidelines = implode("\n", $search->promptGuidelines);
        $this->assertStringContainsString('prior work, conversations, PRs, issues', $searchGuidelines);
        $this->assertStringContainsString('all retained history', $search->parametersJsonSchema['properties']['after']['description']);
        $this->assertStringContainsString('No hits is not proof', $searchGuidelines);
        $this->assertStringContainsString('one contiguous literal substring', $search->description);
        $this->assertStringContainsString('exact phrase', $search->parametersJsonSchema['properties']['query']['description']);
        $this->assertStringContainsString('ASCII case-insensitive', $search->parametersJsonSchema['properties']['query']['description']);
        $this->assertStringContainsString('newest first', $search->parametersJsonSchema['properties']['limit']['description']);
        $this->assertStringContainsString('older matches were omitted', $search->parametersJsonSchema['properties']['limit']['description']);
        $this->assertStringContainsString('not a query match score', $searchGuidelines);
        $this->assertStringContainsString('not raw transcript events', $search->description);
        $this->assertStringContainsString('Use recall for source context behind useful hits', $searchGuidelines);

        $recall = $tools[1];
        $this->assertTrue($recall->mainOnly);
        $this->assertSame('recall', $recall->name);
        $this->assertSame('^[a-f0-9]{12,64}$', $recall->parametersJsonSchema['properties']['id']['pattern'] ?? null);
        $this->assertArrayNotHasKey('session_id', $recall->parametersJsonSchema['properties'] ?? []);

        $this->assertStringContainsString('Recover exact evidence and source context', $recall->description);
        $this->assertStringNotContainsString('current branch', $recall->description);

        $idDescription = (string) ($recall->parametersJsonSchema['properties']['id']['description'] ?? '');
        $this->assertStringContainsString('12–64', $idDescription);
        $this->assertStringContainsString('Not a topic search', $idDescription);
        $this->assertStringContainsString('memory_search', $idDescription);

        $this->assertSame(
            'recall id — retrieve source context for a selected memory',
            $recall->promptSummary,
        );

        $guidelines = implode("\n", $recall->promptGuidelines);
        $this->assertStringContainsString('exact wording, rationale, file paths, commands, errors, commits, user constraints, or provenance', $guidelines);
        $this->assertStringContainsString('user asks why you believe something', $guidelines);
        $this->assertStringContainsString('semantic search or transcript browsing', $recall->description);
        $this->assertStringContainsString('Verify current repo or PR state', $guidelines);
        $this->assertStringNotContainsString('12-character memory id', $guidelines);
    }

    #[Test]
    public function hybridRegistrationDescribesQueryCapabilitiesButNotReranking(): void
    {
        $with = $this->hybridTool();
        $text = $with->description.' '.$with->promptSummary.' '.implode(' ', $with->promptGuidelines).' '.json_encode($with->parametersJsonSchema);
        $this->assertStringContainsString('hybrid keyword', $text);
        $this->assertStringContainsString('semantic search', $text);
        $this->assertStringContainsString('relevance-ranked', $text);
        $this->assertStringContainsString('partial: true means the index is still catching up', $text);
        $this->assertStringContainsString('Phrases are search hints, not exact-match constraints', $text);
        $this->assertStringContainsString('A ranked hit may be unrelated; judge its content', $text);
        $this->assertStringContainsString('truncated: true means a candidate or result limit was reached, not that more relevant memories exist', $text);
        $this->assertStringNotContainsString('exact phrases', $text);
        $this->assertStringNotContainsString('one contiguous literal substring', $text);
        $this->assertStringNotContainsString('try one word', $text);
        $this->assertStringNotContainsString('RRF', $text);
        $this->assertStringNotContainsString('reranker', $text);
    }

    private function hybridTool(): ToolRegistrationDTO
    {
        $tools = [];
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn('/project');
        $api->method('getSettings')->willReturn(['librarian' => ['url' => 'http://librarian.test/_mcp']]);
        $api->expects($this->never())->method('registerSessionStartHook');
        $api->expects($this->exactly(2))->method('registerTool')->willReturnCallback(static function (ToolRegistrationDTO $tool) use (&$tools): void {
            $tools[] = $tool;
        });
        (new ObservationalMemoryExtension())->register($api);
        $this->assertSame(['memory_search', 'recall'], array_column($tools, 'name'));

        return $tools[0];
    }
}
