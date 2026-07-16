<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ForkChildStartRunInputCompositionTest extends IsolatedKernelTestCase
{
    public function testStartRunInputPreservesOrderSanitizesForkCallAndExcludesChildLaunchTools(): void
    {
        $parentRunId = 'parent-fork-compose-1';
        $parentMessages = [
            new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'compact summary']], metadata: ['source' => 'compact_summary']),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'prior user']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'launch fork']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'calling fork']],
                metadata: ['tool_calls' => [['name' => 'fork', 'id' => 'tc-fork-1']]],
            ),
        ];

        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: $parentMessages, turnNo: 1), 0);

        /** @var ForkChildLaunchInputBuilder $builder */
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-1',
            artifactId: 'artifact-fork-1',
            displayName: 'fork',
            taskSummary: 'Delegated task body',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );

        $policy = [
            'tools' => ['read', 'bash'],
            'mcp' => ['mode' => 'inherit', 'tools' => []],
        ];

        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Delegated task body'), $policy);
        $messages = $prepared->startRunInput->messages;
        $roles = array_map(static fn (AgentMessage $m): string => $m->role, $messages);

        $this->assertSame('system', $roles[0]);
        $this->assertContains('user-context', $roles);
        $this->assertSame('user', $roles[array_key_last($roles)]);

        $inheritedUser = array_values(array_filter($messages, static fn (AgentMessage $m): bool => 'user' === $m->role && 'prior user' === ($m->content[0]['text'] ?? '')));
        $this->assertCount(1, $inheritedUser);
        $this->assertSame([], array_filter($messages, static fn (AgentMessage $m): bool => 'assistant' === $m->role && str_contains(json_encode($m->metadata, \JSON_THROW_ON_ERROR), 'fork')));

        $metadata = $prepared->startRunInput->metadata;
        $this->assertSame('agent_child', $metadata->session['kind']);
        $this->assertSame('fork', $metadata->session['child_kind']);
        $this->assertNotContains('fork', $metadata->toolsScope['allowed_tools']);
        $this->assertNotContains('subagent', $metadata->toolsScope['allowed_tools']);
    }

    public function testStartRunInputSystemPromptMatchesCanonicalFirstMessage(): void
    {
        $parentRunId = 'parent-fork-sys-1';
        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: [], turnNo: 1), 0);

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-sys-1',
            artifactId: 'artifact-fork-sys-1',
            displayName: 'fork',
            taskSummary: 'Sys contract task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Sys contract task'), $policy);

        $this->assertNotSame('', trim($prepared->startRunInput->systemPrompt));
        $this->assertSame(
            $prepared->startRunInput->systemPrompt,
            $prepared->startRunInput->messages[0]->content[0]['text'] ?? '',
        );
    }

    public function testCanonicalCompactSummaryUserMessageIsPreservedInInheritedSegment(): void
    {
        $parentRunId = 'parent-fork-compact-1';
        $summaryText = 'COMPACT_SUMMARY_MARKER_XYZ';
        $summaryMessage = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $summaryText]],
            metadata: ['compact_summary' => true],
        );
        $parentMessages = [
            $summaryMessage,
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'after summary']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'launch fork']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'calling fork']],
                metadata: ['tool_calls' => [['name' => 'fork', 'id' => 'tc-fork-compact']]],
            ),
        ];

        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: $parentMessages, turnNo: 1), 0);

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-compact-1',
            artifactId: 'artifact-fork-compact-1',
            displayName: 'fork',
            taskSummary: 'Compact task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Compact task'), $policy);

        $found = array_values(array_filter(
            $prepared->startRunInput->messages,
            static fn (AgentMessage $m): bool => 'user' === $m->role
                && true === ($m->metadata['compact_summary'] ?? null)
                && $summaryText === ($m->content[0]['text'] ?? null),
        ));
        $this->assertCount(1, $found, 'Canonical compact summary must survive sanitizer and composition.');

        $parentAfter = $runStore->get($parentRunId);
        $this->assertCount(\count($parentMessages), $parentAfter->messages);
        $this->assertSame($summaryText, $parentAfter->messages[0]->content[0]['text'] ?? null);
        $this->assertTrue($parentAfter->messages[0]->metadata['compact_summary'] ?? false);
    }

    public function testPreparedForkChildMessagesExcludeAgentsDefinitionsContext(): void
    {
        $parentRunId = 'parent-fork-no-agent-defs';
        $parentMessages = [
            new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => 'AGENT_DEFS_SHOULD_NOT_APPEAR']],
                metadata: ['source' => 'agents_definitions_context'],
            ),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'go']]),
            new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => 'fork']],
                metadata: ['tool_calls' => [['name' => 'fork', 'id' => 'tc-fork-defs']]],
            ),
        ];
        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: $parentMessages, turnNo: 1), 0);

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-defs',
            artifactId: 'artifact-fork-defs',
            displayName: 'fork',
            taskSummary: 'Task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Task'), $policy);

        foreach ($prepared->startRunInput->messages as $message) {
            $this->assertNotSame(
                'agents_definitions_context',
                $message->metadata['source'] ?? null,
                'Fork child must not include agents_definitions_context',
            );
        }
    }

    public function testPreparedForkChildSystemPromptOmitsForkAndSubagentToolGuidance(): void
    {
        $parentRunId = 'parent-fork-sys-tools';
        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: [], turnNo: 1), 0);

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-sys-tools',
            artifactId: 'artifact-fork-sys-tools',
            displayName: 'fork',
            taskSummary: 'Task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read', 'bash'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Task'), $policy);

        $allowed = $prepared->startRunInput->metadata->toolsScope['allowed_tools'] ?? [];
        $this->assertNotContains('fork', $allowed);
        $this->assertNotContains('subagent', $allowed);

        $toolSetResolver = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface::class);
        $eventStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $eventStore->append(new \Ineersa\AgentCore\Domain\Event\RunEvent(
            runId: $identity->childRunId,
            seq: 1,
            turnNo: 0,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'fork child',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'child_kind' => 'fork',
                            'parent_run_id' => $parentRunId,
                            'agent_name' => 'fork',
                            'artifact_id' => $identity->artifactId,
                            'interactive' => true,
                        ],
                        'tools_scope' => [
                            'allowed_tools' => $allowed,
                            'mcp' => ['mode' => 'inherit', 'tools' => []],
                        ],
                    ],
                ],
            ],
        ));
        $active = $toolSetResolver->resolve('default', runId: $identity->childRunId);
        $this->assertNotContains('fork', $active->toolNames);
        $this->assertNotContains('subagent', $active->toolNames);
    }
    public function testForkChildContractListsNoneWhenAllowedToolsEmpty(): void
    {
        $parentRunId = 'parent-fork-empty-tools';
        $runStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\RunStoreInterface::class);
        $runStore->compareAndSwap(new RunState(runId: $parentRunId, status: RunStatus::Running, version: 0, messages: [], turnNo: 1), 0);

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-empty-tools',
            artifactId: 'artifact-fork-empty-tools',
            displayName: 'fork',
            taskSummary: 'Task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => [], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Task'), $policy);

        $contractMessages = array_values(array_filter(
            $prepared->startRunInput->messages,
            static fn (AgentMessage $m): bool => 'user-context' === $m->role
                && 'agent_child_contract' === ($m->metadata['source'] ?? null),
        ));
        $this->assertCount(1, $contractMessages);
        $contractText = (string) ($contractMessages[0]->content[0]['text'] ?? '');
        $this->assertStringContainsString('your active tools are: none', $contractText);
        $this->assertStringNotContainsString('your active tools are: .', $contractText);
    }

}
