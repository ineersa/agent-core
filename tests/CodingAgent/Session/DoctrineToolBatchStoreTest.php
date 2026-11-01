<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\DoctrineToolBatchStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\CountingToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DoctrineToolBatchStoreTest extends IsolatedKernelTestCase
{
    #[DataProvider('groupedLookupSizes')]
    public function testGroupedLookupDeduplicatesBeforeChunkingAndBoundsSelects(int $count, int $selects): void
    {
        $container = self::getContainer();
        $store = $container->get(ToolBatchStoreInterface::class);
        $run = $container->get(HatfieldSessionStore::class)->createSession('grouped boundaries');
        $ids = 0 === $count ? [] : array_map(static fn (int $i): string => 'call-'.$i, range(0, $count - 1));
        if ([] !== $ids) {
            $this->register($store, $run, 1, 'tools', $ids);
        }
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        // Repetition crosses the 400-ID boundary without creating extra chunks.
        $actual = $store->findUnresolvedToolCallIds($run, array_merge($ids, $ids));
        $this->assertEqualsCanonicalizing($ids, $actual);
        $this->assertCount($count, $actual);
        $queries = array_values(array_filter($sql->records, static fn (array $r): bool => str_starts_with($r['context']['sql'] ?? '', 'SELECT')));
        $this->assertCount($selects, $queries);
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(802, \count($query['context']['params']));
            $this->assertStringContainsString('UNION', $query['context']['sql']);
        }
        $sql->records = [];
        $this->assertSame(0 !== $count, $store->hasUnresolvedExecution($run));
        $this->assertCount(1, $sql->records);
    }

    /** @return iterable<string, array{int, int}> */
    public static function groupedLookupSizes(): iterable
    {
        yield 'empty' => [0, 0];
        yield 'one' => [1, 1];
        yield 'one full chunk' => [400, 1];
        yield 'second chunk' => [401, 2];
    }

    public function testGroupedLookupPreservesDeferredHumanAndSharedWorkWithoutReadingBodies(): void
    {
        $container = self::getContainer();
        $store = $container->get(ToolBatchStoreInterface::class);
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('grouped semantics');
        $otherRun = $sessions->createSession('foreign work');
        $deferred = $container->get(DeferredToolCompletionRepositoryInterface::class);
        foreach (['human', 'queued', 'inflight', 'unfinished', 'pending', 'completed', 'settled', 'shared', 'foreign'] as $id) {
            $this->register($store, $run, 1, $id, [$id]);
            if (\in_array($id, ['pending', 'completed'], true)) {
                $deferred->registerPending(new DeferredToolCompletionCorrelation('deferred-'.$id, $run, 1, $id, 1, $id, $id, 'echo', ['text' => $id], 0));
            }
            $this->apply($store, $run, 'settle-'.$id, [new FinalizeToolBatchDTO($run, 1, $id,
                'queued' === $id ? [$id] : [], 'inflight' === $id ? [$id => true] : [],
                'human' === $id ? [$id => 'question'] : [], 'unfinished' !== $id,
            )]);
        }
        $deferred->markCompleted('deferred-completed');
        $this->register($store, $run, 2, 'shared-active', ['shared']);
        $this->register($store, $otherRun, 1, 'foreign', ['foreign']);
        $deferred->registerPending(new DeferredToolCompletionCorrelation('foreign-pending', $otherRun, 1, 'foreign', 1, 'foreign', 'foreign', 'echo', ['text' => 'foreign'], 0));
        // These lookups must remain metadata-only even when retained bodies vanish.
        (new Filesystem())->remove($sessions->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads');
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        $this->assertEqualsCanonicalizing(['human', 'queued', 'inflight', 'unfinished', 'pending', 'shared'], $store->findUnresolvedToolCallIds($run,
            ['human', 'queued', 'inflight', 'unfinished', 'pending', 'completed', 'settled', 'shared', 'foreign', 'absent'],
        ));
        $this->assertCount(1, $sql->records);
        $sql->records = [];
        $this->assertTrue($store->hasUnresolvedExecution($run));
        $this->assertCount(1, $sql->records);
        $deferred->markCompleted('deferred-pending');
        $this->assertSame([], $store->findUnresolvedToolCallIds($run, ['pending', 'completed', 'settled', 'foreign']));
        $this->assertSame(['foreign'], $store->findUnresolvedToolCallIds($otherRun, ['foreign', 'human']));
    }

    public function testPreparationPreservesRetainedReferencesAndOriginalOrder(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('retained references');
        $this->register($store, $run, 1, 'tools', ['a', 'b', 'c']);
        $first = new FinalizeToolBatchDTO($run, 1, 'tools', [], ['b' => true, 'c' => true], [], false, new ToolCallResult($run, 1, 'tools', 1, 'a', 'a', 0, 'first'));
        $this->apply($store, $run, 'first', [$first]);
        $before = $connection->fetchOne('SELECT body_reference_json FROM tool_batch_result WHERE run_id = ? AND tool_call_id = ?', [$run, 'a']);
        $actions = [
            new FinalizeToolBatchDTO($run, 1, 'tools', [], ['c' => true], [], false, new ToolCallResult($run, 1, 'tools', 1, 'b', 'b', 1, 'second')),
            new FinalizeToolBatchDTO($run, 1, 'tools', [], [], [], true, new ToolCallResult($run, 1, 'tools', 1, 'c', 'c', 2, 'third')),
        ];
        $this->apply($store, $run, 'finish', $actions);
        $this->assertSame($before, $connection->fetchOne('SELECT body_reference_json FROM tool_batch_result WHERE run_id = ? AND tool_call_id = ?', [$run, 'a']));
        $batch = $store->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame(['a', 'b', 'c'], array_keys($batch->results));
        $this->assertTrue($batch->finalized);
        $this->apply($store, $run, 'finish', $actions);
        $this->assertEquals($batch, $store->load($run, 1, 'tools'));
    }

    public function testHumanAnswerRewritesOnlyRevisedCallAndRedeliveryDoesNotAdvanceAttempt(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('answer');
        $this->register($store, $run, 1, 'tools', ['a', 'b']);
        $before = $connection->fetchOne('SELECT body_reference_json FROM tool_batch_call WHERE run_id = ? AND tool_call_id = ?', [$run, 'b']);
        $answer = new ToolCallHumanInputAnswerDTO('q', 'allow', ['tool_call_id' => 'a'], ['question_id' => 'q']);
        $action = new FinalizeToolBatchDTO($run, 1, 'tools', [], ['a' => true, 'b' => true], [], false, revisedCallId: 'a', answer: $answer);
        $this->apply($store, $run, 'answered', [$action]);
        $this->apply($store, $run, 'answered', [$action]);
        $batch = $store->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->calls['a']->attempt());
        $this->assertEquals($answer, $batch->calls['a']->humanInputAnswer);
        $this->assertSame($before, $connection->fetchOne('SELECT body_reference_json FROM tool_batch_call WHERE run_id = ? AND tool_call_id = ?', [$run, 'b']));
        $redrive = new FinalizeToolBatchDTO($run, 1, 'tools', [], ['a' => true, 'b' => true], [], false, revisedCallId: 'a');
        $this->apply($store, $run, 'redrive', [$redrive]);
        $redriven = $store->load($run, 1, 'tools');
        $this->assertNotNull($redriven);
        $this->assertNull($redriven->calls['a']->humanInputAnswer);
        $this->assertSame(2, $redriven->calls['a']->attempt());
        $this->assertEquals($batch->calls['b'], $redriven->calls['b']);
    }

    public function testPreparationStillRejectsCorruptUnchangedSibling(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('corrupt');
        $this->register($store, $run, 1, 'tools', ['a']);
        $first = new FinalizeToolBatchDTO($run, 1, 'tools', [], [], [], false, new ToolCallResult($run, 1, 'tools', 1, 'a', 'a', 0, 'first'));
        $this->apply($store, $run, 'first', [$first]);
        $reference = json_decode($connection->fetchOne('SELECT body_reference_json FROM tool_batch_result WHERE run_id = ?', [$run]), true, 512, \JSON_THROW_ON_ERROR);
        $reference['run_id'] = 'another-run';
        $connection->update('tool_batch_result', ['body_reference_json' => json_encode($reference, \JSON_THROW_ON_ERROR)], ['run_id' => $run]);
        $action = new FinalizeToolBatchDTO($run, 1, 'tools', [], [], [], true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored tool batch data reference identity is invalid.');
        $store->prepareChanges([$action], new VerifiedTransitionDTO('finish', ['run_id' => $run, 'actions' => [$action]]));
    }

    public function testBatchPublicationLoadingAndDeletionUseAtMostOneNumericParentLookupPerOperation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('batch paths');
        $paths = new CountingToolBatchRunStoragePaths($container->get(ToolBatchRunStoragePathsInterface::class));
        $payloads = new SessionToolPayloadStore($paths, $container->get('serializer'), $container->get(ValidatorInterface::class), new Filesystem());
        $store = new DoctrineToolBatchStore($container->get(Connection::class), $payloads, $container->get('serializer'), $container->get(ValidatorInterface::class), $container->get(RunLockManager::class), new TestLogger());
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        $ids = array_map(static fn (int $i): string => 'call-'.$i, range(0, 11));
        $this->register($store, $run, 1, 'tools', $ids);
        $this->assertSame(1, $paths->calls);
        $this->assertSame(1, $this->parentCounts($sql));
        $actions = [];
        foreach ($ids as $i => $id) {
            $actions[] = new FinalizeToolBatchDTO($run, 1, 'tools', [], array_fill_keys(\array_slice($ids, $i + 1), true), [], 11 === $i, new ToolCallResult($run, 1, 'tools', 1, $id, $id, $i, 'result-'.$i));
        }
        $paths->calls = 0;
        $sql->records = [];
        $this->apply($store, $run, 'results', $actions);
        $this->assertSame(1, $paths->calls);
        $this->assertSame(1, $this->parentCounts($sql));
        foreach ([true, false] as $full) {
            $paths->calls = 0;
            $sql->records = [];
            $results = $full ? $store->load($run, 1, 'tools')->results : $store->loadResults($run, 1, 'tools');
            $this->assertSame($ids, array_keys($results));
            $this->assertSame(1, $paths->calls);
            $this->assertSame(0, $this->parentCounts($sql), 'Cached paths require no authority query for immutable reads.');
        }
        $paths->calls = 0;
        $sql->records = [];
        $store->delete($run, 1, 'tools');
        $this->assertSame(1, $paths->calls);
        $this->assertSame(0, $this->parentCounts($sql), 'File retirement cannot recreate deleted storage.');
        foreach (['tool_batch_call', 'tool_deferred_completion'] as $table) {
            $this->assertCount(1, array_filter($sql->records, static fn (array $r): bool => str_contains($r['context']['sql'] ?? '', 'SELECT DISTINCT tool_call_id FROM '.$table)), 'Surviving batches and retained deferred evidence each use one batched lookup.');
        }
    }

    public function testScopedDeletesFinishBeforeOwnerReleaseAndRetainSharedAndNewReferences(): void
    {
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('delete target');
        $foreign = $sessions->createSession('foreign');
        $this->register($store, $foreign, 1, 'foreign', array_map(static fn (int $i): string => 'foreign-'.$i, range(1, 32)));
        $this->apply($store, $foreign, 'foreign-result', [new FinalizeToolBatchDTO($foreign, 1, 'foreign', [], ['foreign-2' => true], [], false, new ToolCallResult($foreign, 1, 'foreign', 1, 'foreign-1', 'foreign-1', 0, 'foreign result'))]);
        $this->register($store, $run, 1, 'settled', ['shared', 'new-reference', 'delete-a', 'delete-b']);
        $this->apply($store, $run, 'settled', [new FinalizeToolBatchDTO($run, 1, 'settled', [], [], [], true, new ToolCallResult($run, 1, 'settled', 1, 'shared', 'shared', 0, 'target result'))]);
        $this->register($store, $run, 2, 'survivor', ['shared']);
        // Another eligible batch must survive the narrow delete too.
        $this->register($store, $run, 3, 'other', ['other']);
        $this->apply($store, $run, 'other-settled', [new FinalizeToolBatchDTO($run, 3, 'other', [], [], [], true)]);
        $root = $sessions->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads/';
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        $deletes = [];
        $container->get(RunLockManager::class)->synchronized($run, function () use ($store, $run, $sql, $root, &$deletes): void {
            $store->delete($run, 1, 'settled');
            $deletes = array_values(array_filter($sql->records, static fn (array $r): bool => str_starts_with($r['context']['sql'] ?? '', 'DELETE FROM tool_batch_')));
            // Cleanup completes inside the outer owner's critical section.
            // A later registration republishes its data, with no delayed cleanup.
            $this->assertDirectoryDoesNotExist($root.hash('sha256', 'new-reference'));
            $this->assertDirectoryDoesNotExist($root.hash('sha256', 'delete-a'));
            $this->assertDirectoryExists($root.hash('sha256', 'shared'));
            $this->register($store, $run, 4, 'new', ['new-reference']);
        });
        $this->assertCount(2, $deletes);
        foreach ($deletes as $record) {
            $query = $record['context']['sql'];
            $this->assertStringContainsString('WHERE run_id = ? AND turn_no = ? AND step_id = ? AND EXISTS', $query);
            $plan = $connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$query, $record['context']['params']);
            $details = implode("\n", array_column($plan, 'detail'));
            $table = str_contains($query, 'DELETE FROM tool_batch_call') ? 'tool_batch_call' : 'tool_batch_result';
            $this->assertStringContainsString('SEARCH '.$table, $details);
            $this->assertStringContainsString('run_id=? AND turn_no=? AND step_id=?', $details);
            $this->assertStringNotContainsString('SCAN '.$table, $details);
        }
        foreach (['tool_batch_call', 'tool_deferred_completion'] as $table) {
            $this->assertCount(1, array_filter($sql->records, static fn (array $r): bool => str_contains($r['context']['sql'] ?? '', 'SELECT DISTINCT tool_call_id FROM '.$table)));
        }
        $this->assertDirectoryExists($root.hash('sha256', 'shared'));
        $this->assertDirectoryExists($root.hash('sha256', 'new-reference'));
        $this->assertDirectoryDoesNotExist($root.hash('sha256', 'delete-a'));
        $this->assertDirectoryDoesNotExist($root.hash('sha256', 'delete-b'));
        $this->assertNull($store->loadSchedule($run, 1, 'settled'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM tool_batch_result WHERE run_id = ? AND turn_no = 1', [$run]));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM tool_batch_result WHERE run_id = ?', [$foreign]));
        $this->assertNotNull($store->loadSchedule($run, 3, 'other'));
        $this->assertSame(32, (int) $connection->fetchOne('SELECT COUNT(*) FROM tool_batch_call WHERE run_id = ?', [$foreign]));
        $sql->records = [];
        $store->deleteAllForRun($run);
        foreach (array_filter($sql->records, static fn (array $r): bool => str_starts_with($r['context']['sql'] ?? '', 'DELETE FROM tool_batch_')) as $record) {
            $this->assertStringContainsString('WHERE run_id = ? AND EXISTS', $record['context']['sql']);
            $details = implode("\n", array_column($connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$record['context']['sql'], $record['context']['params']), 'detail'));
            $table = str_contains($record['context']['sql'], 'DELETE FROM tool_batch_call') ? 'tool_batch_call' : 'tool_batch_result';
            $this->assertStringContainsString('SEARCH '.$table, $details);
            $this->assertStringNotContainsString('SCAN '.$table, $details);
            $this->assertStringContainsString('(run_id=?)', $details);
        }
        $this->assertNull($store->loadSchedule($run, 3, 'other'));
        $this->assertNotNull($store->loadSchedule($run, 2, 'survivor'));
        $this->assertDirectoryExists($root.hash('sha256', 'shared'));
        $this->assertSame(32, (int) $connection->fetchOne('SELECT COUNT(*) FROM tool_batch_call WHERE run_id = ?', [$foreign]));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM tool_batch_result WHERE run_id = ?', [$foreign]));
    }

    public function testFinalizedHumanDeferredQueuedAndInflightWorkRetainsBodies(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('unresolved cleanup');
        $store = $container->get(ToolBatchStoreInterface::class);
        $root = $container->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads/';
        foreach (['human', 'deferred', 'queued', 'inflight'] as $step) {
            $this->register($store, $run, 1, $step, [$step]);
            if ('deferred' === $step) {
                $container->get(DeferredToolCompletionRepositoryInterface::class)->registerPending(new DeferredToolCompletionCorrelation('deferred-guard', $run, 1, $step, 1, $step, $step, 'echo', ['text' => $step], 0));
            }
            $this->apply($store, $run, 'final-'.$step, [new FinalizeToolBatchDTO($run, 1, $step, 'queued' === $step ? [$step] : [], 'inflight' === $step ? [$step => true] : [], 'human' === $step ? [$step => 'question'] : [], true)]);
        }
        $store->deleteAllForRun($run);
        foreach (['human', 'deferred', 'queued', 'inflight'] as $step) {
            $this->assertNotNull($store->loadSchedule($run, 1, $step));
            $this->assertDirectoryExists($root.hash('sha256', $step));
        }
        $this->assertNotNull($container->get(DeferredToolCompletionRepositoryInterface::class)->findByDeferredId('deferred-guard'));
    }

    public function testQueuedCallWaitsForCapacityAndMetadataRollbackIsAtomic(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('sql scheduling');
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'key-a', 'call-a', 'echo', ['command' => 'a'], 0);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'key-b', 'call-b', 'echo', ['command' => 'b'], 1);
        $register = new RegisterToolBatchDTO($run, 1, 'tools', [$first, $second], ['call-a' => 0, 'call-b' => 1], ['call-b'], ['call-a' => true], 1);
        $mailbox = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'mailbox-admit', ['text' => 'keep']));
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$register, $mailbox]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $plan = new TransitionPlan($run, $pending, [$register, $mailbox], [], []);
        $failingCommands = $this->createStub(CommandStoreInterface::class);
        $failingCommands->method('prepareEnqueue')->willReturn(static function (): bool {
            throw new \RuntimeException('Injected mailbox failure.');
        });
        $coordinator = new LocalMetadataCoordinator($container->get(ApplicationDbTransactionInterface::class), $batches, $failingCommands, $container->get(StepDispatcher::class));
        try {
            $coordinator->apply($plan);
            $this->fail('Mailbox failure must roll back batch registration.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure.', $exception->getMessage());
        }
        $this->assertNull($batches->load($run, 1, 'tools'));
        $this->assertFalse($commands->has($run, 'mailbox-admit'));
        $this->assertNotNull($events->verifiedPendingTransition($run));

        $coordinator = $container->get(LocalMetadataCoordinator::class);
        $coordinator->apply($plan);
        $events->finalizeVerifiedTransition($run, $pending);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertEquals(['call-a' => $first, 'call-b' => $second], $batch->calls);
        $this->assertSame(['call-a' => true], $batch->inFlight);
        $this->assertSame(['call-b'], $batch->pendingQueue);
        $this->assertTrue($commands->has($run, 'mailbox-admit'));

        $complete = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-b' => true], awaitingHumanInput: [], finalized: false);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$complete]]);
        $next = $events->verifiedPendingTransition($run);
        $this->assertNotNull($next);
        $coordinator->apply(new TransitionPlan($run, $next, [$complete], [], []));
        $events->finalizeVerifiedTransition($run, $next);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame([], $batch->pendingQueue);
        $this->assertSame(['call-b' => true], $batch->inFlight);
        $this->assertEquals($second, $batch->calls['call-b']);
    }

    public function testCapturedAbsenceInsertsWithoutAnUpdateOrApplyTimeRead(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('captured insert');
        $store = $container->get(ToolBatchStoreInterface::class);
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'key', 'call', 'echo', [], 0);
        $action = new RegisterToolBatchDTO($run, 1, 'tools', [$call], ['call' => 0], [], ['call' => true], 1);
        $transition = new VerifiedTransitionDTO('register', ['run_id' => $run, 'actions' => [$action]]);
        $apply = $store->prepareChanges([$action], $transition);
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        $container->get(Connection::class)->transactional($apply);
        $queries = array_column(array_column($sql->records, 'context'), 'sql');
        $this->assertCount(1, preg_grep('/^INSERT INTO tool_batch /', $queries));
        $this->assertCount(0, preg_grep('/^(UPDATE|SELECT) /', $queries));
        $before = $store->load($run, 1, 'tools');
        foreach (['register', 'register-again'] as $identity) {
            $apply = $store->prepareChanges([$action], new VerifiedTransitionDTO($identity, ['run_id' => $run, 'actions' => [$action]]));
            $sql->records = [];
            $container->get(Connection::class)->transactional($apply);
            $queries = array_column(array_column($sql->records, 'context'), 'sql');
            $this->assertCount(0, preg_grep('/^(INSERT|SELECT) /', $queries));
            $this->assertCount('register' === $identity ? 0 : 1, preg_grep('/^UPDATE tool_batch /', $queries));
            $this->assertEquals($before, $store->load($run, 1, 'tools'));
        }
    }

    #[DataProvider('vanishedRowProvider')]
    public function testVanishedExpectedRowsCannotBeResurrected(string $table, string $reason): void
    {
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $run = $container->get(HatfieldSessionStore::class)->createSession('vanished row');
        $store = $container->get(ToolBatchStoreInterface::class);
        $this->register($store, $run, 1, 'tools', ['call']);
        $action = new FinalizeToolBatchDTO($run, 1, 'tools', [], ['call' => true], [], false, revisedCallId: 'call', answer: new ToolCallHumanInputAnswerDTO('q', 'allow', [], []));
        $apply = $store->prepareChanges([$action], new VerifiedTransitionDTO('answer', ['run_id' => $run, 'actions' => [$action]]));
        $connection->delete($table, ['run_id' => $run]);
        $sql = $container->get('test.sql_logger');
        $sql->records = [];
        try {
            $connection->transactional($apply);
            $this->fail('Expected rows must not be recreated after preparation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }
        $queries = array_column(array_column($sql->records, 'context'), 'sql');
        $this->assertCount(0, preg_grep('/^(INSERT|SELECT) /', $queries));
        $this->assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table WHERE run_id = ?", [$run]));
        if ('tool_batch_call' === $table) {
            $this->assertSame('register-1-tools', $connection->fetchOne('SELECT applied_transition FROM tool_batch WHERE run_id = ?', [$run]));
        }
    }

    public static function vanishedRowProvider(): iterable
    {
        yield 'header' => ['tool_batch', 'Prepared tool batch header disappeared before apply.'];
        yield 'revised call' => ['tool_batch_call', 'Prepared tool batch call disappeared before apply.'];
    }

    public function testFailedCleanupTransactionPreservesReferencesAndFiles(): void
    {
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('cleanup rollback');
        $store = $container->get(ToolBatchStoreInterface::class);
        $this->register($store, $run, 1, 'tools', ['call']);
        $this->apply($store, $run, 'settled', [new FinalizeToolBatchDTO($run, 1, 'tools', [], [], [], true, new ToolCallResult($run, 1, 'tools', 1, 'call', 'call', 0, 'retained'))]);
        $before = $store->load($run, 1, 'tools');
        $root = $sessions->resolveSessionsBasePath().'/'.$run.'/runtime/tool-payloads/'.hash('sha256', 'call');
        $connection->executeStatement("CREATE TEMP TRIGGER fail_batch_cleanup BEFORE DELETE ON tool_batch BEGIN SELECT RAISE(ABORT, 'injected cleanup failure'); END");
        try {
            $store->delete($run, 1, 'tools');
            $this->fail('Metadata rollback must prevent file cleanup.');
        } catch (DbalException $exception) {
            $this->assertStringContainsString('injected cleanup failure', $exception->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER fail_batch_cleanup');
        }
        $this->assertDirectoryExists($root);
        $this->assertEquals($before, $store->load($run, 1, 'tools'));
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    /** @param list<string> $ids */
    private function register(ToolBatchStoreInterface $store, string $run, int $turn, string $step, array $ids): void
    {
        $calls = [];
        $order = [];
        foreach ($ids as $i => $id) {
            $calls[] = new ExecuteToolCall($run, $turn, $step, 1, $id, $id, 'echo', ['text' => $id], $i);
            $order[$id] = $i;
        }
        $this->apply($store, $run, 'register-'.$turn.'-'.$step, [new RegisterToolBatchDTO($run, $turn, $step, $calls, $order, [], array_fill_keys($ids, true), \count($ids))]);
    }

    /** @param list<RegisterToolBatchDTO|FinalizeToolBatchDTO> $actions */
    private function apply(ToolBatchStoreInterface $store, string $run, string $identity, array $actions): void
    {
        $apply = $store->prepareChanges($actions, new VerifiedTransitionDTO($identity, ['run_id' => $run, 'actions' => $actions]));
        self::getContainer()->get(Connection::class)->transactional($apply);
    }

    private function parentCounts(TestLogger $sql): int
    {
        return \count(array_filter($sql->records, static fn (array $r): bool => str_contains(strtolower($r['context']['sql'] ?? ''), 'from hatfield_session')));
    }
}
