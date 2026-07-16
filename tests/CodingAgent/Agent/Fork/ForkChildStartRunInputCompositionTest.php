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
        $this->assertSame([], array_filter($messages, static fn (AgentMessage $m): bool => 'assistant' === $m->role && str_contains(json_encode($m->metadata, JSON_THROW_ON_ERROR), 'fork')));

        $metadata = $prepared->startRunInput->metadata;
        $this->assertSame('agent_child', $metadata->session['kind']);
        $this->assertSame('fork', $metadata->session['child_kind']);
        $this->assertNotContains('fork', $metadata->toolsScope['allowed_tools']);
        $this->assertNotContains('subagent', $metadata->toolsScope['allowed_tools']);
    }
}
