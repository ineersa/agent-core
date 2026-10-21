<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunArtifactFinalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;

final class SubagentChildRunArtifactFinalizerTest extends IsolatedKernelTestCase
{
    #[DataProvider('statuses')]
    public function testIdenticalOutcomeDoesNotRewriteAnyArtifact(AgentArtifactStatusEnum $status): void
    {
        $outcome = $this->reserve($status);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply($outcome);
        $completedAt = $registry->get('parent', 'artifact')?->completedAt;
        $root = $this->root();
        $files = [$root.'/registry.json', $root.'/artifact/metadata.json', $root.'/artifact/handoffs/index.json'];
        $history = $registry->listHandoffHistory('parent', 'artifact');
        $files[] = $root.'/artifact/handoffs/'.$history[0]['id'].'.md';
        (new Filesystem())->touch($files, 1234567890);
        foreach (range(1, 4) as $_) {
            $finalizer->apply($outcome);
        }
        clearstatcache();
        foreach ($files as $file) {
            $this->assertSame(1234567890, filemtime($file), $file);
        }
        $this->assertCount(1, $registry->listHandoffHistory('parent', 'artifact'));
        $this->assertEquals($completedAt, $registry->get('parent', 'artifact')?->completedAt);
    }

    public static function statuses(): iterable
    {
        foreach ([AgentArtifactStatusEnum::Completed, AgentArtifactStatusEnum::Failed, AgentArtifactStatusEnum::Cancelled, AgentArtifactStatusEnum::NeedsClarification] as $status) {
            yield $status->value => [$status];
        }
    }

    public function testChangedOutcomeAndResumeKeepImmutableHistoryAndNewCompletionTime(): void
    {
        $first = $this->reserve(AgentArtifactStatusEnum::Completed);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply($first);
        $original = $registry->readHandoff('parent', 'artifact');
        $oldTime = new \DateTimeImmutable('2000-01-01');
        $registry->update('parent', 'artifact', completedAt: $oldTime);
        $changed = new ChildRunTerminalOutcomeDTO($first->identity, AgentArtifactStatusEnum::Failed, 'changed', 'failure', 'question', terminalEventCursor: 1);
        $finalizer->apply($changed);
        $this->assertCount(2, $registry->listHandoffHistory('parent', 'artifact'));
        $this->assertGreaterThan($oldTime, $registry->get('parent', 'artifact')?->completedAt);
        $registry->update('parent', 'artifact', status: AgentArtifactStatusEnum::Running, completedAt: $oldTime);
        $resumed = new ChildRunTerminalOutcomeDTO($first->identity, AgentArtifactStatusEnum::Failed, 'changed', 'failure', 'question', terminalEventCursor: 2);
        $finalizer->apply($resumed);
        $history = $registry->listHandoffHistory('parent', 'artifact');
        $this->assertCount(3, $history);
        $this->assertSame($original, $registry->readHandoffHistoryEntry('parent', 'artifact', $history[0]['id']));
        $this->assertGreaterThan($oldTime, $registry->get('parent', 'artifact')?->completedAt);
    }

    #[DataProvider('partialWrites')]
    public function testRetryCompletesPartialPersistence(string $blocked): void
    {
        $outcome = $this->reserve(AgentArtifactStatusEnum::Completed);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $fs = new Filesystem();
        $path = $this->root().'/artifact/'.$blocked;
        $fs->remove($path);
        $fs->mkdir($path);
        try {
            $finalizer->apply($outcome);
            $this->fail('A directory at the sidecar path must prevent persistence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write', $exception->getMessage());
        }
        // Registry status admits resume only after handoff and metadata are durable.
        $this->assertSame(AgentArtifactStatusEnum::Pending, $registry->get('parent', 'artifact')?->status);
        $this->assertNull($registry->get('parent', 'artifact')?->completedAt);
        $fs->remove($path);
        $finalizer->apply($outcome);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $registry->get('parent', 'artifact')?->status);
        $this->assertCount(1, $registry->listHandoffHistory('parent', 'artifact'));
        $this->assertStringContainsString('summary', $registry->readHandoff('parent', 'artifact'));
        $this->assertFileExists($this->root().'/artifact/metadata.json');
        $finalizer->apply($outcome);
        $this->assertCount(1, $registry->listHandoffHistory('parent', 'artifact'));
    }

    public static function partialWrites(): iterable
    {
        yield 'handoff staged, metadata failed' => ['metadata.json'];
        yield 'handoff body committed, index failed' => ['handoffs/index.json'];
    }

    public function testResumedIdenticalTextRetriesPastAnOlderHandoff(): void
    {
        $first = $this->reserve(AgentArtifactStatusEnum::Completed);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply($first);
        $original = $registry->listHandoffHistory('parent', 'artifact')[0]['id'];
        $registry->update('parent', 'artifact', status: AgentArtifactStatusEnum::Running);
        $resumed = new ChildRunTerminalOutcomeDTO($first->identity, $first->status, $first->summary, terminalEventCursor: 2);
        $fs = new Filesystem();
        $path = $this->root().'/artifact/handoffs/index.json';
        $fs->rename($path, $path.'.saved');
        $fs->mkdir($path);
        try {
            $finalizer->apply($resumed);
            $this->fail('Index persistence must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to write', $exception->getMessage());
        }
        $fs->remove($path);
        $fs->rename($path.'.saved', $path);
        $finalizer->apply($resumed);
        $history = $registry->listHandoffHistory('parent', 'artifact');
        $this->assertCount(2, $history);
        $this->assertSame($original, $history[0]['id']);
        $this->assertNotSame($original, $history[1]['id']);
        $this->assertCount(2, glob($this->root().'/artifact/handoffs/*.md'));
        $finalizer->apply($resumed);
        $this->assertSame($history, $registry->listHandoffHistory('parent', 'artifact'));
    }

    public function testMissingHandoffAndStaleMetadataAreRepaired(): void
    {
        $outcome = $this->reserve(AgentArtifactStatusEnum::Completed);
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $finalizer = self::getContainer()->get(SubagentChildRunArtifactFinalizer::class);
        $finalizer->apply($outcome);
        $history = $registry->listHandoffHistory('parent', 'artifact');
        $fs = new Filesystem();
        $fs->remove($this->root().'/artifact/handoffs/'.$history[0]['id'].'.md');
        $fs->dumpFile($this->root().'/artifact/metadata.json', '{}');
        $finalizer->apply($outcome);
        $this->assertStringContainsString('summary', $registry->readHandoff('parent', 'artifact'));
        $this->assertCount(1, $registry->listHandoffHistory('parent', 'artifact'));
        $this->assertStringContainsString('completed', (string) file_get_contents($this->root().'/artifact/metadata.json'));
        $fs->remove($this->root().'/artifact/metadata.json');
        $finalizer->apply($outcome);
        $this->assertFileExists($this->root().'/artifact/metadata.json');
        $this->assertCount(1, $registry->listHandoffHistory('parent', 'artifact'));
    }

    private function reserve(AgentArtifactStatusEnum $status): ChildRunTerminalOutcomeDTO
    {
        self::getContainer()->get(AgentArtifactRegistry::class)->create('parent', 'artifact', 'child', 'worker', AgentArtifactKindEnum::Subagent);

        return new ChildRunTerminalOutcomeDTO(new ChildRunIdentityDTO('parent', 'child', 'artifact', 'worker', 'task', AgentArtifactKindEnum::Subagent), $status, 'summary', terminalEventCursor: 1);
    }

    private function root(): string
    {
        return self::getContainer()->get(AppConfig::class)->cwd.'/.hatfield/sessions/parent/artifacts/agents';
    }
}
