<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class ForkParentRunStoreReadOnceTest extends IsolatedKernelTestCase
{
    public function testBuildPreparedReadsParentRunStoreExactlyOnce(): void
    {
        $parentRunId = 'parent-fork-read-once-1';
        $inner = new InMemoryRunStore();
        $spy = new CountingRunStoreDecorator($inner);
        $inner->compareAndSwap(new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 0,
            messages: [
                new AgentMessage(role: 'user-context', content: [['type' => 'text', 'text' => 'AGENTS']], metadata: ['source' => 'agents_context']),
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]),
            ],
            turnNo: 1,
        ), 0);

        $builder = new ForkChildLaunchInputBuilder(
            $spy,
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Fork\ForkRuntimeConfigResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\ModelResolver::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Skills\SkillsContextBuilder::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Config\AppConfig::class),
        );

        $identity = new ChildRunIdentityDTO(
            parentRunId: $parentRunId,
            childRunId: 'child-read-once-1',
            artifactId: 'artifact-read-once-1',
            displayName: 'fork',
            taskSummary: 'Read once task',
            definitionModel: null,
            artifactKind: AgentArtifactKindEnum::Fork,
        );
        $policy = ['tools' => ['read'], 'mcp' => ['mode' => 'inherit', 'tools' => []]];

        $builder->buildPrepared($identity, new ForkLaunchTaskDTO(task: 'Read once task'), $policy);

        $this->assertSame(1, $spy->getCount, 'Fork preparation must load parent RunState exactly once.');
    }
}

final class CountingRunStoreDecorator implements \Ineersa\AgentCore\Contract\RunStoreInterface
{
    public int $getCount = 0;

    public function __construct(private readonly \Ineersa\AgentCore\Contract\RunStoreInterface $inner)
    {
    }

    public function get(string $runId): ?RunState
    {
        ++$this->getCount;

        return $this->inner->get($runId);
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        return $this->inner->compareAndSwap($state, $expectedVersion);
    }

    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        return $this->inner->findRunningStaleBefore($updatedBefore);
    }
}
