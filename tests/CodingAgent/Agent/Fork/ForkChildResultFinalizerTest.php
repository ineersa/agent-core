<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkChildResultFinalizer;
use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidator;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
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
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Tests for ForkChildResultFinalizer.
 *
 * Test thesis:
 *   - Completed run with valid handoff writes handoff.md and marks Completed.
 *   - Invalid handoff after max repair attempts writes candidate-handoff.md
 *     and marks Failed with diagnostics.
 *   - Cancelled run marks Cancelled with clear message.
 *   - Failed run marks Failed with error.
 *   - Run with no assistant response marks Failed.
 */
#[CoversClass(ForkChildResultFinalizer::class)]
#[AllowMockObjectsWithoutExpectations]
final class ForkChildResultFinalizerTest extends TestCase
{
    private AgentRunnerInterface&\PHPUnit\Framework\MockObject\MockObject $agentRunner;
    private RunStoreInterface&\PHPUnit\Framework\MockObject\MockObject $runStore;
    private AgentArtifactRegistry $artifactRegistry;
    private AgentArtifactPathResolver $pathResolver;
    private ForkChildResultFinalizer $finalizer;
    private string $tmpDir;
    private string $parentRunId;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('fork-finalizer-test');
        TestDirectoryIsolation::createHatfieldTree($this->tmpDir, withSessions: true);

        $this->parentRunId = 'parent-'.bin2hex(random_bytes(8));

        $this->agentRunner = $this->createMock(AgentRunnerInterface::class);
        $this->runStore = $this->createMock(RunStoreInterface::class);

        // Real AgentArtifactRegistry with minimal dependencies.
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );

        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $this->pathResolver = new AgentArtifactPathResolver($sessionStore);

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
            [new JsonEncoder()],
        );

        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $this->pathResolver = new AgentArtifactPathResolver($sessionStore);

        $this->artifactRegistry = new AgentArtifactRegistry(
            pathResolver: $this->pathResolver,
            serializer: $serializer,
            validator: $validator,
            lockFactory: new LockFactory(new FlockStore()),
        );

        $this->finalizer = new ForkChildResultFinalizer(
            agentRunner: $this->agentRunner,
            runStore: $this->runStore,
            artifactRegistry: $this->artifactRegistry,
            handoffValidator: new ForkHandoffValidator(),
            logger: new NullLogger(),
            maxRepairAttempts: 2,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    /**
     * Create the artifact entry before running finalizer.
     */
    private function createArtifact(string $artifactId, string $agentRunId): string
    {
        $entry = $this->artifactRegistry->create(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: 'fork',
            kind: AgentArtifactKindEnum::Fork,
        );

        return $entry->artifactId;
    }

    /**
     * Build a terminal RunState for testing.
     */
    private function buildTerminalState(RunStatus $status, string $assistantText = ''): RunState
    {
        $messages = [];
        if ('' !== $assistantText) {
            $messages[] = new AgentMessage(
                role: 'assistant',
                content: [['type' => 'text', 'text' => $assistantText]],
            );
        }

        return new RunState(
            runId: 'test_child_run',
            status: $status,
            messages: $messages,
            version: 1,
        );
    }

    /**
     * Get the absolute artifact result directory for a given artifact.
     */
    private function resultDirFor(string $artifactId): string
    {
        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);

        return $this->pathResolver->absolutePath($this->parentRunId, $entry->paths->artifactDir);
    }

    // ── Test: Valid handoff ──────────────────────────────────────────────

    public function testValidHandoffWritesCompleted(): void
    {
        $artifactId = $this->createArtifact('art_valid', 'child_run_valid');
        $validHandoff = <<<'HANDOFF'
## 1. Result / status

Task complete. 3 files changed.

## 5. Changes made

Updated src/SomeFile.php

## 11. Final handoff

Done.
HANDOFF;

        $state = $this->buildTerminalState(RunStatus::Completed, $validHandoff);
        $resultDir = $this->resultDirFor($artifactId);

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('child_run_valid')
            ->willReturn($state);

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_valid',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'middle',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Completed, $result->status);
        self::assertNull($result->error);

        // Verify artifact entry is completed.
        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Completed, $entry->status);
        self::assertNotNull($entry->completedAt);

        // Verify handoff.md was written.
        self::assertFileExists($resultDir.'/handoff.md');
    }

    // ── Test: Invalid after max repair attempts ──────────────────────────

    public function testInvalidAfterMaxRepairAttemptsFails(): void
    {
        $artifactId = $this->createArtifact('art_max_fail', 'child_run_max_fail');
        $invalidHandoff = 'Still not a valid handoff.';
        $resultDir = $this->resultDirFor($artifactId);

        // First state: Completed with invalid handoff.
        $firstState = $this->buildTerminalState(RunStatus::Completed, $invalidHandoff);
        // Second state (repair 1): still invalid.
        $secondState = $this->buildTerminalState(RunStatus::Completed, $invalidHandoff);
        // Third state (repair 2): still invalid.
        $thirdState = $this->buildTerminalState(RunStatus::Completed, $invalidHandoff);

        $this->runStore->expects(self::exactly(3))
            ->method('get')
            ->with('child_run_max_fail')
            ->willReturnOnConsecutiveCalls($firstState, $secondState, $thirdState);

        // Expect two followUp calls (max 2 attempts).
        $this->agentRunner->expects(self::exactly(2))
            ->method('followUp');

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_max_fail',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'middle',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Failed, $result->status);
        self::assertNotNull($result->error);
        self::assertStringContainsString('Invalid handoff', $result->error ?? '');
        self::assertSame(3, $result->validationAttempts);
        self::assertNotNull($result->candidateHandoffPath);
        self::assertFileExists($result->candidateHandoffPath);

        // Verify artifact entry is failed.
        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
    }

    // ── Test: Cancelled run ──────────────────────────────────────────────

    public function testCancelledRunRecordsCancelled(): void
    {
        $artifactId = $this->createArtifact('art_cancel', 'child_run_cancel');
        $state = $this->buildTerminalState(RunStatus::Cancelled);
        $resultDir = $this->resultDirFor($artifactId);

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('child_run_cancel')
            ->willReturn($state);

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_cancel',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'middle',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Cancelled, $result->status);
        self::assertNotNull($result->error);
        self::assertStringContainsString('cancelled', $result->error ?? '');

        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Cancelled, $entry->status);
    }

    // ── Test: Failed run ─────────────────────────────────────────────────

    public function testFailedRunRecordsFailed(): void
    {
        $artifactId = $this->createArtifact('art_fail', 'child_run_fail');
        $resultDir = $this->resultDirFor($artifactId);

        $state = new RunState(
            runId: 'test_child_fail',
            status: RunStatus::Failed,
            messages: [],
            version: 1,
            errorMessage: 'LLM provider error',
        );

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('child_run_fail')
            ->willReturn($state);

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_fail',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'senior',
            resolvedModel: 'openai/gpt-4',
        );

        self::assertSame(AgentArtifactStatusEnum::Failed, $result->status);
        self::assertStringContainsString('LLM provider error', $result->error ?? '');

        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
        self::assertStringContainsString('LLM provider error', $entry->failureReason ?? '');
    }

    // ── Test: Run state not found ────────────────────────────────────────

    public function testRunStateNotFoundFails(): void
    {
        $artifactId = $this->createArtifact('art_lost', 'child_run_lost');
        $resultDir = $this->resultDirFor($artifactId);

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('child_run_lost')
            ->willReturn(null);

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_lost',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'junior',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Failed, $result->status);
        self::assertStringContainsString('not found', $result->error ?? '');

        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
    }

    // ── Test: No assistant response ──────────────────────────────────────

    public function testCompletedWithNoAssistantResponseFails(): void
    {
        $artifactId = $this->createArtifact('art_no_resp', 'child_run_no_response');
        $resultDir = $this->resultDirFor($artifactId);

        $state = new RunState(
            runId: 'test_child_no_response',
            status: RunStatus::Completed,
            messages: [
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'User message']],
                ),
            ],
            version: 1,
        );

        $this->runStore->expects(self::once())
            ->method('get')
            ->with('child_run_no_response')
            ->willReturn($state);

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_no_response',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'middle',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Failed, $result->status);
        self::assertStringContainsString('no assistant response', $result->error ?? '');

        $entry = $this->artifactRegistry->get($this->parentRunId, $artifactId);
        self::assertNotNull($entry);
        self::assertSame(AgentArtifactStatusEnum::Failed, $entry->status);
    }

    // ── Test: Repair then valid handoff ──────────────────────────────────

    public function testRepairThenValidHandoffSucceeds(): void
    {
        $artifactId = $this->createArtifact('art_repair', 'child_run_repair');
        $resultDir = $this->resultDirFor($artifactId);

        $invalidHandoff = 'I did the thing but not formatted right.';
        $repairedHandoff = <<<'HANDOFF'
## 1. Result / status

Task complete. No filesystem changes made.

## 5. Changes made

No filesystem changes made.

## 11. Final handoff

Done.
HANDOFF;

        $firstState = $this->buildTerminalState(RunStatus::Completed, $invalidHandoff);
        $secondState = $this->buildTerminalState(RunStatus::Completed, $repairedHandoff);

        $this->runStore->expects(self::exactly(2))
            ->method('get')
            ->with('child_run_repair')
            ->willReturnOnConsecutiveCalls($firstState, $secondState);

        $this->agentRunner->expects(self::once())
            ->method('followUp');

        $result = $this->finalizer->finalize(
            parentRunId: $this->parentRunId,
            artifactId: $artifactId,
            childRunId: 'child_run_repair',
            resultDir: $resultDir,
            cwd: '/tmp',
            task: 'Test task',
            level: 'middle',
            resolvedModel: null,
        );

        self::assertSame(AgentArtifactStatusEnum::Completed, $result->status);
        self::assertNull($result->error);
        self::assertSame(1, $result->validationAttempts);

        // Verify handoff written.
        self::assertFileExists($resultDir.'/handoff.md');
        $content = file_get_contents($resultDir.'/handoff.md');
        self::assertStringContainsString('No filesystem changes made', $content ?? '');
    }
}
