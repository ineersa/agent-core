<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentChildLaunchModelInheritanceTest extends IsolatedKernelTestCase
{
    public function testExplicitChildModelWinsOverParentSnapshot(): void
    {
        $parentRunId = 'parent-explicit-model-reasoning';
        $this->seedParentRunStarted($parentRunId, reasoning: 'medium');
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: $this->identity($parentRunId, 'deepseek/deepseek-v4-flash'),
            definition: $this->definition('deepseek/deepseek-v4-flash', thinking: 'high'),
            allowedTools: [],
            mcp: [],
            parentModel: 'openai-codex/gpt-5.6-sol',
        );

        $this->assertSame('deepseek/deepseek-v4-flash', $prepared->startRunInput->metadata?->model);
        $this->assertSame('high', $prepared->startRunInput->metadata?->reasoning);
    }

    public function testMissingExplicitUsesParentSnapshot(): void
    {
        $parentRunId = 'parent-inherit-model-reasoning';
        $this->seedParentRunStarted($parentRunId, reasoning: 'medium');
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: $this->identity($parentRunId, null),
            definition: $this->definition(null),
            allowedTools: [],
            mcp: [],
            parentModel: 'deepseek/deepseek-v4-flash',
        );

        $this->assertSame('deepseek/deepseek-v4-flash', $prepared->startRunInput->metadata?->model);
        $this->assertSame('medium', $prepared->startRunInput->metadata?->reasoning);
    }

    public function testMissingParentAndExplicitFailsClosed(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $this->expectException(\RuntimeException::class);
        $factory->buildPrepared(
            identity: $this->identity('parent-missing-model', null),
            definition: $this->definition(null),
            allowedTools: [],
            mcp: [],
            parentModel: null,
        );
    }

    public function testInheritedProjectContextComesFromCanonicalReplayNotLegacySnapshot(): void
    {
        $parentRunId = 'parent-canonical-user-context';
        $canonicalContext = 'CANONICAL_AGENTS_CONTEXT';
        $this->seedParentRunStarted($parentRunId, reasoning: 'medium', messages: [[
            'role' => 'user-context',
            'content' => [['type' => 'text', 'text' => $canonicalContext]],
            'metadata' => ['source' => 'agents_context'],
        ]]);

        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);
        $prepared = $factory->buildPrepared(
            identity: $this->identity($parentRunId, 'deepseek/deepseek-v4-flash'),
            definition: $this->definition('deepseek/deepseek-v4-flash'),
            allowedTools: [],
            mcp: [],
            parentModel: 'deepseek/deepseek-v4-flash',
        );

        $contexts = array_filter(
            $prepared->startRunInput->messages,
            static fn (AgentMessage $message): bool => 'agents_context' === ($message->metadata['source'] ?? null),
        );
        $this->assertCount(1, $contexts);
        $context = array_values($contexts)[0];
        $this->assertSame($canonicalContext, $context->content[0]['text'] ?? null);
    }

    public function testMissingParentRunStartedReasoningUsesCanonicalDefault(): void
    {
        $parentRunId = 'parent-missing-reasoning';
        $this->seedParentRunStarted($parentRunId, reasoning: null);
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: $this->identity($parentRunId, null),
            definition: $this->definition(null),
            allowedTools: [],
            mcp: [],
            parentModel: 'deepseek/deepseek-v4-flash',
        );

        $this->assertSame('deepseek/deepseek-v4-flash', $prepared->startRunInput->metadata?->model);
        // Canonical ModelResolver product default when run_started omits reasoning.
        $this->assertSame('medium', $prepared->startRunInput->metadata?->reasoning);
        $this->assertSame('medium', $prepared->identity->launchReasoning);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function seedParentRunStarted(string $parentRunId, ?string $reasoning, array $messages = []): void
    {
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        \assert($eventStore instanceof EventStoreInterface);
        $metadata = [
            'session' => ['kind' => 'parent'],
            'model' => 'deepseek/deepseek-v4-flash',
        ];
        if (null !== $reasoning) {
            $metadata['reasoning'] = $reasoning;
        }
        $eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['payload' => ['metadata' => $metadata, 'messages' => $messages]],
            createdAt: new \DateTimeImmutable(),
        ));
    }

    private function identity(string $parentRunId, ?string $model): ChildRunIdentityDTO
    {
        return new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-1',
            artifactId: 'agent_child1',
            displayName: 'scout',
            taskSummary: 'task',
            launchModel: $model ?? 'deepseek/deepseek-v4-flash', launchReasoning: 'medium',
            artifactKind: AgentArtifactKindEnum::Subagent,
        );
    }

    private function definition(?string $model, ?string $thinking = null): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'scout',
            description: 'd',
            tools: [],
            model: $model,
            thinking: $thinking,
            instructions: 'do work',
        );
    }
}
