<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidator;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Controller\ForkRunFinalizer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests for ForkRunFinalizer (event-driven fork-mode finalization service).
 *
 * Test theses:
 *   - Completed run with valid handoff writes handoff.md, fork-metadata.json,
 *     and .fork-finalized marker; marks Completed.
 *   - Invalid handoff after max repair attempts writes candidate-handoff.md
 *     + diagnostics and marks Failed.
 *   - Cancelled run marks Cancelled; Cancelling status is NOT treated as
 *     terminal (finalize returns immediately without marking done).
 *   - Failed run marks Failed with error.
 *   - Run state not found (run_lost) marks Failed.
 *   - Metadata is written to fork-metadata.json (NOT metadata.json).
 *   - .fork-finalized marker is written after all artifact paths.
 *   - Already-finalized run is idempotent (no-op on subsequent calls).
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ForkRunFinalizer::class)]
final class ForkRunFinalizerTest extends TestCase
{
    private AgentRunnerInterface&MockObject $agentRunner;
    private RunStoreInterface&MockObject $runStore;
    private AgentArtifactRegistry $artifactRegistry;
    private string $tmpDir;
    private string $parentRunId;
    private string $artifactId;
    private string $childRunId;
    private string $resultDir;
    private ForkRunFinalizer $watcher;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('fork-finalizer-test');
        TestDirectoryIsolation::createHatfieldTree($this->tmpDir, withSessions: true);

        $this->parentRunId = 'parent-'.bin2hex(random_bytes(8));
        $this->artifactId = 'artifact-'.bin2hex(random_bytes(4));
        $this->childRunId = 'child-'.bin2hex(random_bytes(8));
        $this->resultDir = $this->tmpDir.'/fork-result';

        $this->agentRunner = $this->createMock(AgentRunnerInterface::class);
        $this->runStore = $this->createMock(RunStoreInterface::class);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );

        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $pathResolver = new AgentArtifactPathResolver($sessionStore);

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
            [new JsonEncoder()],
        );

        $validator = (new \Symfony\Component\Validator\ValidatorBuilder())->enableAttributeMapping()->getValidator();
        $lockFactory = new LockFactory(new FlockStore($this->tmpDir));

        $this->artifactRegistry = new AgentArtifactRegistry(
            pathResolver: $pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: $lockFactory,
        );

        $this->artifactRegistry->create(
            parentRunId: $this->parentRunId,
            artifactId: $this->artifactId,
            agentRunId: $this->childRunId,
            agentName: 'fork-child',
            kind: AgentArtifactKindEnum::Fork,
        );

        $this->watcher = new ForkRunFinalizer(
            runStore: $this->runStore,
            agentRunner: $this->agentRunner,
            artifactRegistry: $this->artifactRegistry,
            handoffValidator: new ForkHandoffValidator(),
            logger: new NullLogger(),
        );

        mkdir($this->resultDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    // ── Helpers ──

    private function makeValidHandoff(): string
    {
        return implode("\n\n", [
            "## 1. Result / status",
            "Task complete. 1 file changed.",
            "",
            "## 5. Changes made",
            "Edited src/Foo.php with bug fix.",
            "",
            "## 11. Final handoff",
            "The fix is ready for review.",
        ]);
    }

    private function makeRunState(RunStatus $status, string $assistantText = '', ?string $errorMessage = null): RunState
    {
        $messages = [];
        if ('' !== $assistantText) {
            $messages[] = new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => $assistantText]],
            );
        }

        return new RunState(
            runId: $this->childRunId,
            status: $status,
            messages: $messages,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Create standard fork options array for tests.
     *
     * @return array<string, mixed>
     */
    private function defaultForkOptions(): array
    {
        return [
            'fork_parent_run_id' => $this->parentRunId,
            'fork_artifact_id' => $this->artifactId,
            'fork_child_run_id' => $this->childRunId,
            'fork_result_dir' => $this->resultDir,
            'fork_cwd' => $this->tmpDir,
            'fork_task' => 'test-task',
            'fork_level' => 'middle',
        ];
    }

    // ── Tests ──

    public function testCompletedWithValidHandoff(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Completed, $this->makeValidHandoff()));

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $handoff = $this->artifactRegistry->readHandoff($this->parentRunId, $this->artifactId);
        $this->assertStringContainsString('## 1. Result / status', $handoff);

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Completed->value, $meta['status'] ?? null);
        $this->assertSame($this->childRunId, $meta['child_run_id'] ?? null);

        $this->assertFileExists($this->resultDir.'/.fork-finalized');

        $entry = $this->artifactRegistry->get($this->parentRunId, $this->artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
    }

    public function testCompletedWithShortHandoffFailsAfterMaxRepairs(): void
    {
        $shortHandoff = 'Short';
        $state = $this->makeRunState(RunStatus::Completed, $shortHandoff);

        // RunStore returns the same state for each call (no actual follow-up advancement).
        $this->runStore->method('get')->willReturn($state);

        $this->agentRunner->expects($this->exactly(ForkRunFinalizer::MAX_REPAIR_ATTEMPTS))
            ->method('followUp')
            ->with($this->childRunId, $this->isInstanceOf(AgentMessage::class));

        // Call finalize N+1 times (each repair attempt + final failure).
        for ($i = 0; $i <= ForkRunFinalizer::MAX_REPAIR_ATTEMPTS; $i++) {
            $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());
        }

        $this->assertFileExists($this->resultDir.'/candidate-handoff.md');
        $this->assertFileExists($this->resultDir.'/handoff-validation.json');

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Failed->value, $meta['status'] ?? null);

        $this->assertFileExists($this->resultDir.'/.fork-finalized');
    }

    public function testCancelledRun(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Cancelled));

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Cancelled->value, $meta['status'] ?? null);
        $this->assertStringContainsString('cancelled', $meta['error'] ?? '');
        $this->assertFileExists($this->resultDir.'/.fork-finalized');

        $entry = $this->artifactRegistry->get($this->parentRunId, $this->artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Cancelled, $entry->status);
    }

    public function testCancellingNotTerminal(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Cancelling));

        // The finalizer should not treat Cancelling as terminal.
        // No artifact registry calls should be made.
        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileDoesNotExist($this->resultDir.'/fork-metadata.json');
        $this->assertFileDoesNotExist($this->resultDir.'/.fork-finalized');
    }

    public function testFailedRun(): void
    {
        $state = $this->makeRunState(RunStatus::Failed, 'Some partial output', 'Test error message');

        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($state);

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Failed->value, $meta['status'] ?? null);
        $this->assertStringContainsString('Test error message', $meta['error'] ?? '');
        $this->assertFileExists($this->resultDir.'/candidate-handoff.md');
        $this->assertFileExists($this->resultDir.'/.fork-finalized');
    }

    public function testRunLost(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn(null);

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Failed->value, $meta['status'] ?? null);
        $this->assertStringContainsString('not found', $meta['error'] ?? '');
        $this->assertFileExists($this->resultDir.'/.fork-finalized');
    }

    public function testRunningIsNotTerminal(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Running));

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileDoesNotExist($this->resultDir.'/fork-metadata.json');
        $this->assertFileDoesNotExist($this->resultDir.'/.fork-finalized');
    }

    public function testAlreadyFinalizedIsIdempotent(): void
    {
        $state = $this->makeRunState(RunStatus::Completed, $this->makeValidHandoff());

        // RunStore is called once (first finalize), second call should be no-op.
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($state);

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());
        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);
        $this->assertSame(AgentArtifactStatusEnum::Completed->value, $meta['status'] ?? null);
    }

    public function testMetadataInForkMetadataJson(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Completed, $this->makeValidHandoff()));

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');

        // Artifact registry metadata (inside .hatfield/...) should also be Completed.
        $entry = $this->artifactRegistry->get($this->parentRunId, $this->artifactId);
        $this->assertNotNull($entry);
        $this->assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
    }

    public function testForkMetadataJsonShape(): void
    {
        $this->runStore->expects($this->once())
            ->method('get')
            ->with($this->childRunId)
            ->willReturn($this->makeRunState(RunStatus::Completed, $this->makeValidHandoff()));

        $this->watcher->finalize($this->childRunId, $this->defaultForkOptions());

        $this->assertFileExists($this->resultDir.'/fork-metadata.json');
        $meta = json_decode(file_get_contents($this->resultDir.'/fork-metadata.json'), true);

        $this->assertArrayHasKey('fork_run_id', $meta);
        $this->assertArrayHasKey('parent_run_id', $meta);
        $this->assertArrayHasKey('child_run_id', $meta);
        $this->assertArrayHasKey('kind', $meta);
        $this->assertArrayHasKey('status', $meta);
        $this->assertArrayHasKey('level', $meta);
        $this->assertArrayHasKey('cwd', $meta);
        $this->assertArrayHasKey('task', $meta);
        $this->assertArrayHasKey('completed_at', $meta);

        $this->assertSame($this->artifactId, $meta['fork_run_id']);
        $this->assertSame($this->parentRunId, $meta['parent_run_id']);
        $this->assertSame($this->childRunId, $meta['child_run_id']);
        $this->assertSame('fork_child', $meta['kind']);
        $this->assertSame(AgentArtifactStatusEnum::Completed->value, $meta['status']);
    }
}
