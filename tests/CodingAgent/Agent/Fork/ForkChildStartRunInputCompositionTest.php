<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkLaunchTaskDTO;
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

        /** @var ForkChildLaunchInputBuilder $builder */
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-1',
            artifactId: 'artifact-fork-1',
            displayName: 'fork',
            taskSummary: 'Delegated task body',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );

        $policy = [
            'tools' => ['read', 'bash'],
            'mcp' => ['mode' => 'inherit', 'tools' => []],
        ];

        $sanitizer = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class);
        $inherited = $sanitizer->sanitize($parentMessages);
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Delegated task body', inheritedMessages: $inherited, reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );
        $messages = $prepared->startRunInput->messages;
        $roles = array_map(static fn (AgentMessage $m): string => $m->role, $messages);

        $this->assertSame('system', $roles[0]);
        // user-context is only present when agents/skills context is non-empty;
        // agent_child_contract is intentionally absent for fork children.
        $this->assertNotContains('agent_child_contract', array_map(
            static fn (AgentMessage $m): string => (string) ($m->metadata['source'] ?? ''),
            $messages,
        ));
        $this->assertSame('user', $roles[array_key_last($roles)]);
        $this->assertStringContainsString('FORK MODE IS ENABLED', $prepared->startRunInput->systemPrompt);

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
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-sys-1',
            artifactId: 'artifact-fork-sys-1',
            displayName: 'fork',
            taskSummary: 'Sys contract task',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Sys contract task', inheritedMessages: [], reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );

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

        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-compact-1',
            artifactId: 'artifact-fork-compact-1',
            displayName: 'fork',
            taskSummary: 'Compact task',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $sanitizer = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class);
        $inherited = $sanitizer->sanitize($parentMessages);
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Compact task', inheritedMessages: $inherited, reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );

        $found = array_values(array_filter(
            $prepared->startRunInput->messages,
            static fn (AgentMessage $m): bool => 'user' === $m->role
                && true === ($m->metadata['compact_summary'] ?? null)
                && $summaryText === ($m->content[0]['text'] ?? null),
        ));
        $this->assertCount(1, $found, 'Canonical compact summary must survive sanitizer and composition.');
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
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-defs',
            artifactId: 'artifact-fork-defs',
            displayName: 'fork',
            taskSummary: 'Task',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $sanitizer = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class);
        $inherited = $sanitizer->sanitize($parentMessages);
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Task', inheritedMessages: $inherited, reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );

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
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-sys-tools',
            artifactId: 'artifact-fork-sys-tools',
            displayName: 'fork',
            taskSummary: 'Task',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read', 'bash'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Task', inheritedMessages: [], reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );

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
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
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

    public function testForkChildOmitsAgentChildContractAndKeepsFinalityPlusCompactHandoffContract(): void
    {
        $parentRunId = 'parent-fork-no-contract';
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-fork-no-contract',
            artifactId: 'artifact-fork-no-contract',
            displayName: 'fork',
            taskSummary: 'Task',
            launchModel: 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];
        $prepared = $builder->buildPrepared(
            $identity,
            new ForkLaunchTaskDTO(task: 'Task', inheritedMessages: [], reasoningOverride: 'medium'),
            $policy,
            parentModel: 'test-model',
        );

        $contractMessages = array_values(array_filter(
            $prepared->startRunInput->messages,
            static fn (AgentMessage $m): bool => 'user-context' === $m->role
                && 'agent_child_contract' === ($m->metadata['source'] ?? null),
        ));
        $this->assertCount(0, $contractMessages, 'Fork child must not receive agent_child_contract user-context');

        $joined = '';
        foreach ($prepared->startRunInput->messages as $message) {
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $joined .= (string) $block['text']."\n";
                }
            }
        }

        $this->assertStringNotContainsString('Artifact ID: artifact-fork-no-contract', $joined);
        $this->assertStringNotContainsString('agent_child_contract', $joined);
        $this->assertStringContainsString('FORK MODE IS ENABLED', $prepared->startRunInput->systemPrompt);
        $this->assertStringContainsString('Never emit the handoff in a message that also requests tools', $prepared->startRunInput->systemPrompt);
        $this->assertStringContainsString('## Status', $joined);
        $this->assertStringContainsString('## Repository state', $joined);
        $this->assertStringContainsString('## Result', $joined);
        $this->assertStringContainsString('## Validation', $joined);
        $this->assertStringContainsString('Return the semantic delta produced by this fork, not a transcript.', $joined);
        $this->assertSame('user', $prepared->startRunInput->messages[array_key_last($prepared->startRunInput->messages)]->role);
    }
}
