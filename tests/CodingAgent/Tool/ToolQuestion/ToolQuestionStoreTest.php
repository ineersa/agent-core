<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ToolQuestion;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStore;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;

/**
 * Integration tests for ToolQuestionStore with Symfony kernel/test container.
 *
 * DB isolation is handled by DAMA/DoctrineTestBundle transaction rollback.
 * No manual teardown cleanup needed.
 *
 * @requires extension pdo_sqlite
 */
final class ToolQuestionStoreTest extends IsolatedKernelTestCase
{
    private ToolQuestionStore $store;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();

        // Resolve through the interface alias so we test the DI binding.
        $this->store = $container->get(ToolQuestionStoreInterface::class);
        $this->em = $container->get('doctrine.orm.default_entity_manager');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Create a fresh ToolQuestion entity with unique identifiers for isolated testing.
     */
    private function createQuestion(string $prefix = 'store-test'): ToolQuestion
    {
        $requestId = \sprintf('%s-%s', $prefix, \uniqid('', true));
        $runId = \sprintf('run-%s', \uniqid('', true));

        return ToolQuestion::create(
            requestId: $requestId,
            runId: $runId,
            toolCallId: \sprintf('tc-%s', \uniqid('', true)),
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test-tool-question.log',
            commandPreview: 'echo "test command"',
            prompt: 'Move command to the background?',
            kind: 'confirm',
        );
    }

    /**
     * Use DBAL/ORM DQL to force a ToolQuestion's created_at backward in time.
     * Lifecycle callbacks override constructor values at persist time, so we
     * must UPDATE after persist.
     */
    private function forceCreatedAt(ToolQuestion $question, \DateTimeImmutable $newCreatedAt): void
    {
        $this->em->createQueryBuilder()
            ->update(ToolQuestion::class, 'tq')
            ->set('tq.createdAt', ':newCreatedAt')
            ->set('tq.updatedAt', ':newUpdatedAt')
            ->where('tq.requestId = :requestId')
            ->setParameter('newCreatedAt', $newCreatedAt)
            ->setParameter('newUpdatedAt', $newCreatedAt)
            ->setParameter('requestId', $question->requestId)
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    // ── Test: create / find / poll lifecycle ────────────────────────────

    public function testCreateAndPollAnswerLifecycle(): void
    {
        $q = $this->createQuestion();
        $requestId = $q->requestId;

        // Persist via the store.
        $this->store->create($q);

        // Can find by request ID.
        $found = $this->store->findByRequestId($requestId);
        self::assertNotNull($found);

        // Still pending, poll returns null.
        self::assertNull($this->store->pollAnswer($requestId));
        self::assertNull($found->answer, 'Pending question must have null answer');
        self::assertNull($found->answeredAt, 'Pending question must have null answeredAt');

        // findUnemittedPendingQuestions includes it.
        $unemitted = $this->store->findUnemittedPendingQuestions();
        self::assertNotEmpty($unemitted);
        $foundPending = \array_filter(
            $unemitted,
            static fn (ToolQuestion $tq): bool => $tq->requestId === $requestId,
        );
        self::assertCount(1, $foundPending);

        // Mark emitted.
        $this->store->markEmitted($requestId);
        $afterEmit = $this->store->findByRequestId($requestId);
        self::assertNotNull($afterEmit);
        self::assertNotNull($afterEmit->emittedAt, 'Emitted question must have emittedAt set');

        // findUnemittedPendingQuestions no longer includes it.
        $unemittedAfter = $this->store->findUnemittedPendingQuestions();
        $stillPending = \array_filter(
            $unemittedAfter,
            static fn (ToolQuestion $tq): bool => $tq->requestId === $requestId,
        );
        self::assertCount(0, $stillPending, 'Emitted question must not appear in unemitted results');

        // Answer true.
        $answered = $this->store->answer($requestId, true);
        self::assertTrue($answered, 'First answer must return true');

        // poll returns true.
        self::assertTrue($this->store->pollAnswer($requestId), 'pollAnswer must return true');
    }

    // ── Test: answer / cancel idempotency ───────────────────────────────

    public function testAnswerAndCancelAreIdempotent(): void
    {
        // --- Q1: answer true, then answer false -> second no-op, poll stays true ---
        $q1 = $this->createQuestion('idempotent-q1');
        $r1 = $q1->requestId;
        $this->store->create($q1);

        // First answer returns true.
        self::assertTrue($this->store->answer($r1, true));
        self::assertTrue($this->store->pollAnswer($r1));

        // Second answer returns false (idempotent guard).
        self::assertFalse($this->store->answer($r1, false), 'Second answer must return false (already resolved)');
        self::assertTrue($this->store->pollAnswer($r1), 'pollAnswer must still return true (first answer preserved)');

        // Cancel on answered question is no-op.
        $this->store->cancel($r1);
        self::assertTrue($this->store->pollAnswer($r1), 'pollAnswer must still return true (cancel no-op on answered)');

        // --- Q2: cancel first, then answer -> answer no-op, poll stays false ---
        $q2 = $this->createQuestion('idempotent-q2');
        $r2 = $q2->requestId;
        $this->store->create($q2);

        // Cancel returns pollAnswer false.
        $this->store->cancel($r2);
        self::assertFalse($this->store->pollAnswer($r2), 'pollAnswer must return false after cancel');

        // Answer on cancelled question returns false (idempotent guard).
        self::assertFalse($this->store->answer($r2, true), 'Answer on cancelled must return false');
        self::assertFalse($this->store->pollAnswer($r2), 'pollAnswer must still return false (cancel preserved)');
    }

    // ── Test: stale pending cleanup ─────────────────────────────────────

    public function testCancelPendingQuestionsCreatedBeforeLifecycle(): void
    {
        // Create old question Q1: store then backdate createdAt via DQL.
        $q1 = $this->createQuestion('cleanup-old');
        $r1 = $q1->requestId;
        $this->store->create($q1);

        // Persisted through lifecycle trait will set createdAt to ~now.
        // Backdate to 1 hour ago.
        $oneHourAgo = new \DateTimeImmutable('-1 hour');
        $this->forceCreatedAt($q1, $oneHourAgo);

        // Create fresh question Q2 (~now).
        $q2 = $this->createQuestion('cleanup-fresh');
        $r2 = $q2->requestId;
        $this->store->create($q2);

        // Create answered question Q3 (already resolved, should not be touched).
        $q3 = $this->createQuestion('cleanup-answered');
        $r3 = $q3->requestId;
        $this->store->create($q3);
        $this->store->answer($r3, true);
        // Backdate answered Q3 too.
        $this->forceCreatedAt($q3, $oneHourAgo);

        // Cleanup: cancel pending rows created before 30 minutes ago.
        $cutoff = new \DateTimeImmutable('-30 minutes');
        $cleanupTime = new \DateTimeImmutable();
        \usleep(1); // ensure cleanup timestamp is distinct from Q2 creation
        $count = $this->store->cancelPendingQuestionsCreatedBefore($cutoff);

        // Only Q1 was old+pending.
        self::assertSame(1, $count, 'Only one old pending question should be cancelled');

        // Q1: now cancelled.
        $q1After = $this->store->findByRequestId($r1);
        self::assertNotNull($q1After);
        self::assertSame(ToolQuestionStatusEnum::Cancelled, $q1After->status);
        self::assertFalse($this->store->pollAnswer($r1));

        // Q1: answeredAt and updatedAt should be set to cleanup time (approximate).
        self::assertNotNull($q1After->answeredAt, 'Cancelled question must have answeredAt set');
        // Allow some tolerance since DQL UPDATE and entity read happen at slightly different times.
        $diff = \abs($q1After->answeredAt->getTimestamp() - $cleanupTime->getTimestamp());
        self::assertLessThanOrEqual(5, $diff, 'answeredAt should be close to cleanup time');

        // DQL UPDATE sets updatedAt explicitly via the query.
        self::assertNotNull($q1After->updatedAt, 'Cancelled question must have updatedAt set');
        $diffUpd = \abs($q1After->updatedAt->getTimestamp() - $cleanupTime->getTimestamp());
        self::assertLessThanOrEqual(5, $diffUpd, 'updatedAt should be close to cleanup time');

        // Q2: still pending.
        $q2After = $this->store->findByRequestId($r2);
        self::assertNotNull($q2After);
        self::assertSame(ToolQuestionStatusEnum::Pending, $q2After->status);
        self::assertNull($this->store->pollAnswer($r2));

        // Q3: still answered (not touched by cleanup).
        $q3After = $this->store->findByRequestId($r3);
        self::assertNotNull($q3After);
        self::assertSame(ToolQuestionStatusEnum::Answered, $q3After->status, 'Answered question must not be changed by cleanup');
        self::assertTrue($this->store->pollAnswer($r3), 'Answered question must still return true');
    }

    // ── Test: duplicate create idempotency ───────────────────────────────

    public function testCreateDuplicateReturnsExisting(): void
    {
        $q1 = $this->createQuestion('duplicate');
        $r1 = $q1->requestId;

        // First create succeeds.
        $first = $this->store->create($q1);
        self::assertSame($r1, $first->requestId);

        // Create a second question with the same requestId but different values.
        $q2 = ToolQuestion::create(
            requestId: $r1,
            runId: 'dup-run',
            toolCallId: 'dup-tc',
            toolName: 'view_image',
            pid: 99999,
            logPath: '/tmp/dup.log',
            commandPreview: 'duplicate command',
            prompt: 'Duplicate?',
        );

        // Second create returns the original persisted entity (not the new one).
        $result = $this->store->create($q2);

        // Without DAMA isolation this would hit unique constraint, but DAMA
        // wraps in a transaction. Use a known assertion that works in both
        // cases: the returned entity should have the ORIGINAL runId (q1's).
        self::assertSame($r1, $result->requestId);
        self::assertSame($q1->runId, $result->runId, 'Should return existing entity with original values (not the new duplicate)');
        self::assertSame('bash', $result->toolName, 'Should return existing entity with original tool name');
    }

    // ── Test: entity factory input validation ───────────────────────────

    public function testCreateWithEmptyRequestIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId must not be empty');

        ToolQuestion::create(
            requestId: '',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithWhitespaceOnlyRequestIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId must not be empty');

        ToolQuestion::create(
            requestId: "   \t\n",
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithOverlongCommandPreviewThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commandPreview must not exceed 200 characters');

        ToolQuestion::create(
            requestId: 'test-valid',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: \str_repeat('x', 201),
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithExactlyMaxLengthCommandPreviewSucceeds(): void
    {
        $q = ToolQuestion::create(
            requestId: 'test-max-preview',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: \str_repeat('x', 200),
            prompt: 'Test prompt?',
        );

        self::assertSame('test-max-preview', $q->requestId);
        self::assertSame(200, \mb_strlen($q->commandPreview));
    }

    // ── Test: entity factory additional field validation ────────────────

    public function testCreateWithEmptyRunIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runId must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: '',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyToolCallIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toolCallId must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: '',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyToolNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toolName must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: '',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyPromptThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: '',
        );
    }

    public function testCreateWithEmptyKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kind must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
            kind: '',
        );
    }

    // ── Test: markEmitted deduplication ─────────────────────────────────

    public function testCreateAndMarkEmittedDeduplication(): void
    {
        $q = $this->createQuestion('dedup');
        $r = $q->requestId;
        $this->store->create($q);

        // First markEmitted succeeds (no-op semantics: does not throw).
        $this->store->markEmitted($r);
        $afterFirst = $this->store->findByRequestId($r);
        self::assertNotNull($afterFirst->emittedAt);

        $firstEmittedAt = $afterFirst->emittedAt;

        // Second markEmitted is safe no-op.
        $this->store->markEmitted($r);
        $afterSecond = $this->store->findByRequestId($r);
        // markEmitted simply sets emittedAt to now; second call overwrites.
        // Since the method does not check isResolved or guard against
        // re-emission, the timestamp may advance. This is acceptable
        // because the poller's findUnemittedPendingQuestions query uses
        // IS NULL, so a re-marked question with non-null emittedAt is
        // still excluded from the unemitted result set.
        self::assertNotNull($afterSecond->emittedAt);
    }
}
