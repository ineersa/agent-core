<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\History\HistoryReplayPlan;
use Ineersa\CodingAgent\Session\History\RunPresentationReader;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Session\RunHistorySourceDTO;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

final class RunHistoryIndexTest extends IsolatedKernelTestCase
{
    private JsonlRunEventLog $log;
    private TestLogger $logger;
    private string $path;
    private int $publishedSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->log = new JsonlRunEventLog(static::getContainer()->get(EventPayloadNormalizer::class), static::getContainer()->get(LockFactory::class), static::getContainer()->get(RunSequenceAllocatorInterface::class), new EventLogMaxSeqBootstrapReader(), $this->logger, presentations: HistoryEventStoreFactory::presentationFactory());
        $this->path = getcwd().'/.hatfield/sessions/indexed/events.jsonl';
        (new Filesystem())->mkdir(\dirname($this->path));
    }

    public function testSchemaBootstrapRollsBackAllNewObjectsOnDdlFailure(): void
    {
        $db = $this->db();
        $db->executeStatement('CREATE TABLE event_location (seq INTEGER PRIMARY KEY)');
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        // Exercise the schema transaction directly: public synchronization repairs
        // a corrupt index and would hide whether bootstrap used separate commits.
        try {
            try {
                $this->expectException(\Doctrine\DBAL\Exception::class);
                (new \ReflectionMethod(RunHistoryIndex::class, 'createSchema'))->invoke($index, $db);
            } finally {
                $this->assertFalse($db->isTransactionActive());
                $this->assertSame(['event_location'], $db->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"));
            }
        } finally {
            $db->close();
        }
    }

    public function testMixedPagesHaveDistinctChronologicalCursorsAndSeekOnlyTheNextPrompt(): void
    {
        $seq = 0;
        $fullNextPrompt = str_repeat('Next prompt with full text. ', 100);
        for ($i = 0; $i < 40; ++$i) {
            $turn = 100 - $i;
            $this->write(++$seq, $turn, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 1 === $i ? $fullNextPrompt : 'Prompt '.$turn]);
            $this->write(++$seq, $turn, 'turn_advanced', ['turn_no' => $turn]);
            $this->write(++$seq, $turn, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer '.$turn]]]]);
        }
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $all = [];
        $after = 0;
        do {
            $page = $index->entryPage($this->log, $this->path, 'indexed', after: $after);
            $this->assertLessThanOrEqual(32, \count($page['rows']));
            array_push($all, ...$page['rows']);
            $after = $page['newer'];
        } while (null !== $after);
        $this->assertCount(80, $all);
        $this->assertCount(80, array_unique(array_column($all, 'cursor')));
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column(\array_slice($all, 0, 4), 'role'));
        $this->assertSame([100, 100, 99, 99], array_column(\array_slice($all, 0, 4), 'turn_no'));
        $this->assertSame([2, 2, 5, 5], array_column(\array_slice($all, 0, 4), 'anchor'));
        $older = $index->entryPage($this->log, $this->path, 'indexed', before: $all[32]['cursor']);
        $this->assertSame(\array_slice($all, 0, 32), $older['rows']);
        $this->logger->records = [];
        $answer = $index->selectEntry($this->log, $this->path, 'indexed', 100, 'assistant');
        $this->assertSame(['position' => 61, 'predecessor' => 100, 'text' => $fullNextPrompt, 'selectedPromptTurnNo' => 99], $answer);
        $this->assertSame(1, $this->readLines('selected_prompt'));
        $this->assertSame(0, $this->readBytes('index_cold_rebuild'));
        $last = $index->selectEntry($this->log, $this->path, 'indexed', 61, 'assistant');
        $this->assertSame('', $last['text']);
        $this->assertSame(0, $last['selectedPromptTurnNo']);
    }

    public function testColdAnswerIndexExcludesToolAndCommentaryResponsesAndRetainsForwardAnswersUntilDiscard(): void
    {
        $comment = \Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem::toText(['id' => 'comment', 'type' => 'message', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Checking']]]);
        $responses = [
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Tool commentary']], 'tool_calls' => [['id' => 'call']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Checking', 'text_signature' => $comment->getSignature()]]],
            ['role' => 'assistant', 'content' => [['type' => 'thinking', 'text' => 'Reasoning only']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Final answer']]],
        ];
        $seq = 0;
        foreach ($responses as $i => $message) {
            $this->write(++$seq, $i + 1, 'turn_advanced', ['turn_no' => $i + 1]);
            $this->write(++$seq, $i + 1, 'llm_step_completed', ['assistant_message' => $message]);
        }
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $this->assertSame([4], array_column($index->entryPage($this->log, $this->path, 'indexed', after: 0)['rows'], 'turn_no'));
        (new Filesystem())->remove([\dirname($this->path).'/history-index.sqlite', \dirname($this->path).'/history-index.sqlite-wal', \dirname($this->path).'/history-index.sqlite-shm']);
        $this->write(++$seq, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->assertSame([4], array_column($index->entryPage($this->log, $this->path, 'indexed', after: 0)['rows'], 'turn_no'), 'Selection is not deletion, including after a cold index rebuild.');
        $this->write(++$seq, 1, 'history_tail_discarded', ['after_turn_no' => 1]);
        $this->assertSame([], $index->entryPage($this->log, $this->path, 'indexed', after: 0)['rows']);
        $this->expectException(\RuntimeException::class);
        $index->selectEntry($this->log, $this->path, 'indexed', 4, 'assistant');
    }

    public function testMixedPageQueriesSeekTheAssistantIndexWithoutTemporaryOrdering(): void
    {
        $this->write(1, 1, 'turn_advanced', ['turn_no' => 1]);
        $this->write(2, 1, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer']]]]);
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $index->entryPage($this->log, $this->path, 'indexed', after: 0);
        // Bind the plan proof to the actual mixed paging UNION, not a simplified
        // answer-only SELECT that could conceal a scan or sort in the real page.
        $entries = "(SELECT anchor, anchor AS cursor, turn_no, prompt_preview AS preview, 'user' AS role FROM turn_anchor WHERE retained = 1 AND prompt_seq IS NOT NULL UNION ALL SELECT anchor, answer_seq AS cursor, turn_no, answer_preview AS preview, 'assistant' AS role FROM turn_anchor WHERE retained = 1 AND answer_seq IS NOT NULL)";
        $file = (new \ReflectionClass(RunHistoryIndex::class))->getFileName();
        $this->assertIsString($file);
        $source = file_get_contents($file);
        $this->assertIsString($source);
        $this->assertStringContainsString('$entries = "'.$entries.'";', $source);
        $db = $this->db();
        try {
            foreach (['<' => 'DESC', '>' => 'ASC'] as $operator => $direction) {
                foreach (['SELECT * FROM '.$entries.' WHERE cursor '.$operator.' ? ORDER BY cursor '.$direction.' LIMIT '.RunHistoryIndex::PAGE_SIZE,
                    'SELECT cursor FROM '.$entries.' WHERE cursor '.$operator.' ? LIMIT 1'] as $sql) {
                    $plan = implode("\n", array_column($db->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql, [2]), 'detail'));
                    $this->assertMatchesRegularExpression('/SEARCH turn_anchor USING (?:COVERING )?INDEX turn_answers \(answer_seq[<>]/', $plan, $sql."\n".$plan);
                    $this->assertStringNotContainsString('SCAN turn_anchor', $plan, $sql."\n".$plan);
                    $this->assertStringNotContainsString('TEMP B-TREE', $plan, $sql."\n".$plan);
                }
            }
        } finally {
            $db->close();
        }
    }

    public function testColdRebuildWarmSeekAndSuffixBytesArePhysical(): void
    {
        $bytes = 0;
        for ($seq = 1; $seq <= 20; ++$seq) {
            $bytes += $this->write($seq * 3, $seq, 'turn_advanced', ['turn_no' => $seq, 'text' => str_repeat('x', 1024)]);
        }
        $this->assertSame([60], $this->sequences($this->log->indexedLines($this->path, 'indexed', 60, 60)));
        $this->assertSame($bytes, $this->readBytes('index_cold_rebuild'));
        $this->logger->records = [];
        $this->assertSame([30], $this->sequences($this->log->indexedLines($this->path, 'indexed', 30, 30)));
        $this->assertSame(1, $this->readLines('sequence_range'));
        $this->assertLessThan($bytes / 5, $this->readBytes('sequence_range') + $this->readBytes('index_boundary_validation'));
        $this->assertSame(0, $this->readBytes('index_cold_rebuild'));
        $this->logger->records = [];
        $suffix = $this->write(99, 20, 'agent_end');
        $this->assertSame([99], $this->sequences($this->log->indexedLines($this->path, 'indexed', 99, 99)));
        $this->assertSame($suffix, $this->readBytes('index_suffix_catch_up'));
        $this->assertSame($suffix, $this->readBytes('sequence_range'));
        $db = $this->db();
        try {
            $this->assertSame(99, (int) $db->fetchOne('SELECT last_seq FROM index_meta'));
            $this->assertSame($bytes + $suffix, (int) $db->fetchOne('SELECT end_offset FROM index_meta'));
        } finally {
            $db->close();
        }
    }

    public function testAnswerSeekIndexRebuildsDisposableSchemasWithAndWithoutAnswerColumns(): void
    {
        $this->write(1, 1, 'turn_advanced', ['turn_no' => 1]);
        $this->write(2, 1, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer']]]]);
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $expected = $index->entryPage($this->log, $this->path, 'indexed', after: 0)['rows'];
        foreach ([4, 3] as $version) {
            $db = $this->db();
            $db->executeStatement('DROP INDEX turn_answers');
            $db->executeStatement('UPDATE index_meta SET version = ?', [$version]);
            if (3 === $version) {
                $db->executeStatement('ALTER TABLE turn_anchor DROP COLUMN answer_seq');
                $db->executeStatement('ALTER TABLE turn_anchor DROP COLUMN answer_preview');
            }
            $db->close();
            $this->assertSame($expected, $index->entryPage($this->log, $this->path, 'indexed', after: 0)['rows']);
            $db = $this->db();
            try {
                $this->assertSame(8, (int) $db->fetchOne('SELECT version FROM index_meta'));
                $this->assertSame('CREATE INDEX turn_answers ON turn_anchor (answer_seq) WHERE retained = 1 AND answer_seq IS NOT NULL', $db->fetchOne("SELECT sql FROM sqlite_master WHERE name = 'turn_answers'"));
            } finally {
                $db->close();
            }
        }
    }

    public function testPromptPagesAndExactSparseSelectionMatchReferencePolicy(): void
    {
        $text = str_repeat("original\n", 200);
        $fixtures = [
            [1, 0, 'run_started', ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Initial']]]]]],
            [3, 100, 'turn_advanced', []],
            [5, 80, 'turn_advanced', []],
            [7, 80, 'agent_command_queued', ['kind' => 'follow_up', 'text' => 'Queued only']],
            [9, 80, 'agent_command_rejected', ['kind' => 'steer', 'text' => 'Rejected']],
            [11, 80, 'agent_command_applied', ['kind' => 'append_message', 'text' => 'Generated']],
            [13, 80, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 'Replaced']],
            [15, 80, 'agent_command_applied', ['kind' => 'steer', 'message' => ['content' => [['type' => 'text', 'text' => $text]]]]],
            [17, 4, 'turn_advanced', []],
            [19, 4, 'llm_step_completed', []],
            [21, 4, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 'Discarded pending']],
            [23, 100, 'history_position_set', ['position_turn_no' => 100, 'reason' => 'history_select']],
            [25, 100, 'history_tail_discarded', ['after_turn_no' => 100]],
            [27, 80, 'turn_advanced', []],
            [29, 80, 'agent_command_applied', ['kind' => 'follow_up', 'text' => $text]],
            [31, 4, 'turn_advanced', []],
            [33, 80, 'history_position_set', ['position_turn_no' => 80, 'reason' => 'history_select']],
        ];
        $events = [];
        $promptBytes = 0;
        foreach ($fixtures as [$seq, $turn, $type, $payload]) {
            $bytes = $this->write($seq, $turn, $type, $payload);
            if (29 === $seq) {
                $promptBytes = $bytes;
            }
            $events[] = new RunEvent('indexed', $seq, $turn, $type, $payload);
        }
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $page = $index->entryPage($this->log, $this->path, 'indexed', after: 0);
        $reference = (new \Ineersa\CodingAgent\Tests\Support\HistoryReferenceProjector())->build($events);
        $this->assertSame(array_keys($reference->promptsByTurnNo), array_column($page['rows'], 'turn_no'));
        $this->assertSame([3, 31], array_column($page['rows'], 'anchor'));
        $this->assertSame(RunHistoryIndex::PREVIEW_BYTES, \strlen($page['rows'][1]['preview']));
        $this->assertSame(27, $page['selected']);
        $this->logger->records = [];
        $prompt = $index->selectEntry($this->log, $this->path, 'indexed', 4);
        $this->assertSame(['position' => 80, 'predecessor' => 80, 'text' => $text, 'selectedPromptTurnNo' => 4], $prompt);
        $this->assertSame($promptBytes, $this->readBytes('selected_prompt'));
        $this->assertSame(1, $this->readLines('selected_prompt'));
        $this->assertSame(0, $this->readBytes('index_cold_rebuild'));
        $this->assertSame(0, $this->readBytes('sequence_range'));
        $this->logger->records = [];
        $near = $index->entryPage($this->log, $this->path, 'indexed');
        $this->assertSame([4], array_column($near['rows'], 'turn_no'));
        $this->assertSame(31, $near['older']);
        $this->assertSame(0, $this->readBytes('selected_prompt'));
        $this->assertSame(1, $this->readLines('index_boundary_validation'));
    }

    public function testExactPromptLocationFailureRefusesAndInvalidatesDisposableEvidence(): void
    {
        $this->write(1, 0, 'run_started', ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]]);
        $this->write(3, 1, 'turn_advanced');
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $index->entryPage($this->log, $this->path, 'indexed');
        $db = $this->db();
        $db->executeStatement('UPDATE event_location SET offset = (SELECT offset FROM event_location WHERE seq = 3), length = (SELECT length FROM event_location WHERE seq = 3) WHERE seq = 1');
        $db->close();
        $canonical = file_get_contents($this->path);
        try {
            $index->selectEntry($this->log, $this->path, 'indexed', 1);
            $this->fail('A mismatching prompt location must not be accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }
        $this->assertSame($canonical, file_get_contents($this->path));
        $this->assertFileExists(\dirname($this->path).'/history-index.sqlite.unavailable');
        $this->assertSame('Original', $index->selectEntry($this->log, $this->path, 'indexed', 1)['text']);
        $this->assertFileDoesNotExist(\dirname($this->path).'/history-index.sqlite.unavailable');
    }

    public function testEveryPromptIsReachableThroughBoundedAnchorPagesAndOldSchemaRebuilds(): void
    {
        for ($i = 1; $i <= 80; ++$i) {
            $this->write($i * 3, 81 - $i, 'agent_command_applied', ['kind' => 'follow_up', 'text' => str_repeat('p'.$i, 300)]);
            $this->write($i * 3 + 1, 81 - $i, 'turn_advanced');
        }
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $tail = $index->entryPage($this->log, $this->path, 'indexed');
        $this->assertCount(32, $tail['rows']);
        $this->assertSame(1, $tail['rows'][31]['turn_no']);
        $outsidePage = $index->selectEntry($this->log, $this->path, 'indexed', 80);
        $this->assertSame(0, $outsidePage['predecessor']);
        $this->assertSame(str_repeat('p1', 300), $outsidePage['text']);
        $all = [];
        $after = 0;
        $this->logger->records = [];
        do {
            $page = $index->entryPage($this->log, $this->path, 'indexed', after: $after);
            $this->assertLessThanOrEqual(32, \count($page['rows']));
            $this->assertLessThanOrEqual(32 * 240, array_sum(array_map(static fn (array $row): int => \strlen($row['preview']), $page['rows'])));
            array_push($all, ...array_column($page['rows'], 'turn_no'));
            $after = $page['newer'];
        } while (null !== $after);
        $this->assertSame(range(80, 1), $all);
        $this->assertSame(3, $this->readLines('index_boundary_validation'));
        $this->assertSame(0, $this->readBytes('index_cold_rebuild'));
        $older = $index->entryPage($this->log, $this->path, 'indexed', before: $tail['older']);
        $this->assertSame(range(64, 33), array_column($older['rows'], 'turn_no'));
        $db = $this->db();
        $db->executeStatement('ALTER TABLE turn_anchor DROP COLUMN prompt_seq');
        $db->executeStatement('ALTER TABLE turn_anchor DROP COLUMN prompt_preview');
        $db->executeStatement('DROP TABLE pending_prompt');
        $db->executeStatement('UPDATE index_meta SET version = 2');
        $db->close();
        $this->logger->records = [];
        $rebuilt = $index->entryPage($this->log, $this->path, 'indexed');
        $this->assertSame($tail, $rebuilt);
        $this->assertSame(filesize($this->path), $this->readBytes('index_cold_rebuild'));
    }

    public function testForwardTailUsesStableRetainedOrderAndOnlyValidatesBoundaryWhenWarm(): void
    {
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 0));
        $this->write(3, 100, 'turn_advanced');
        $this->write(9, 4, 'turn_advanced');
        $boundaryBytes = $this->write(15, 100, 'history_position_set', ['position_turn_no' => 100]);
        $this->assertTrue($index->hasForwardTail($this->log, $this->path, 'indexed', 100));
        $this->logger->records = [];
        $this->assertTrue($index->hasForwardTail($this->log, $this->path, 'indexed', 0));
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 4));
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 99));
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', -1));
        $this->assertSame(3 * $boundaryBytes, $this->readBytes('index_boundary_validation'));
        $this->assertSame(0, $this->readBytes('index_cold_rebuild'));
        $this->assertSame(0, $this->readBytes('sequence_range'));
        $this->write(21, 100, 'history_tail_discarded', ['after_turn_no' => 100]);
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 100));
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 4));
        $this->write(27, 4, 'turn_advanced');
        $this->assertTrue($index->hasForwardTail($this->log, $this->path, 'indexed', 100));
        $this->assertFalse($index->hasForwardTail($this->log, $this->path, 'indexed', 4));
    }

    public function testOnlyVerifiedFinalizationPublishesIndexAndFailureDegradesLocally(): void
    {
        $this->log->appendMany($this->path, [RunEvent::forAppend('indexed', 0, 'run_started')], work: ['run_id' => 'indexed', 'predecessor_seq' => 0]);
        $this->assertSame([], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $pending = $this->log->verifiedPendingTransition($this->path, 'indexed');
        $this->assertNotNull($pending);
        $this->log->finalizeVerifiedTransition($this->path, 'indexed', $pending);
        $this->assertSame([1], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $db = $this->db();
        $db->beginTransaction();
        $db->executeStatement('UPDATE index_meta SET version = version');
        try {
            $this->log->appendMany($this->path, [RunEvent::forAppend('indexed', 1, 'turn_advanced')], work: ['run_id' => 'indexed', 'predecessor_seq' => 1]);
            $pending = $this->log->verifiedPendingTransition($this->path, 'indexed');
            $this->assertNotNull($pending);
            $this->log->finalizeVerifiedTransition($this->path, 'indexed', $pending);
            $this->assertFileDoesNotExist($this->path.'.append.pending.json');
            $this->assertFileExists(\dirname($this->path).'/history-index.sqlite.unavailable');
            $this->assertCount(1, array_filter($this->logger->records, static fn (array $r): bool => 'history_index.publication_failed' === $r['message']));
        } finally {
            $db->rollBack();
            $db->close();
        }
        $this->assertSame([1, 2], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $this->assertFileDoesNotExist(\dirname($this->path).'/history-index.sqlite.unavailable');
    }

    public function testReusedDisplayedTurnNumbersMatchIndependentLinearHistoryReference(): void
    {
        $this->write(1, 0, 'run_started');
        $this->write(3, 1, 'turn_advanced');
        $this->write(5, 1, 'tool_execution_start');
        $this->write(9, 2, 'turn_advanced');
        $this->write(11, 2, 'tool_execution_end');
        $this->write(13, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->write(15, 1, 'history_tail_discarded', ['after_turn_no' => 1]);
        $this->write(17, 1, 'agent_command_queued');
        $this->write(19, 2, 'turn_advanced');
        $this->write(21, 2, 'tool_execution_end');
        $this->write(23, 2, 'agent_command_rejected');
        $this->write(25, 2, 'context_compacted');
        // Independent reference: append immutable nodes, truncate after selection,
        // then append the replacement node. Displayed "2" is never the node key.
        $nodes = [[3, 5], [9, 11]];
        $nodes = \array_slice($nodes, 0, 1);
        $nodes[] = [17, 19, 21, 23, 25];
        $reference = [1, ...$nodes[0], 13, 15, ...$nodes[1]];
        $this->assertSame($reference, $this->sequences($this->retainedLines()));
        $this->write(27, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->assertSame([1, 3, 5, 13, 15, 27], $this->sequences($this->retainedLines()));
        $db = $this->db();
        try {
            $this->assertSame([['anchor' => 9, 'retained' => 0], ['anchor' => 19, 'retained' => 1]], $db->fetchAllAssociative('SELECT anchor, retained FROM turn_anchor WHERE turn_no = 2 ORDER BY anchor'));
            $this->assertSame(3, (int) $db->fetchOne('SELECT predecessor FROM turn_anchor WHERE anchor = 19'));
            $this->assertSame(19, (int) $db->fetchOne('SELECT anchor FROM context_checkpoint_location WHERE seq = 25'));
        } finally {
            $db->close();
        }
    }

    public function testCommandSuppressionAndCompactionMatchExistingReference(): void
    {
        foreach ([[1, 0, 'run_started', []], [2, 1, 'agent_command_queued', []], [3, 1, 'turn_advanced', []], [5, 1, 'llm_step_completed', []], [7, 1, 'agent_command_queued', []], [9, 1, 'agent_command_applied', []], [11, 1, 'agent_command_rejected', []], [13, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']], [15, 1, 'context_compacted', []]] as [$seq, $turn, $type, $payload]) {
            $this->write($seq, $turn, $type, $payload);
        }
        $events = [];
        foreach ($this->log->forwardLines($this->path) as $line) {
            $events[] = $this->log->denormalizeRunEvent($this->log->decodeLine($line));
        }
        $reference = HistoryReplayPlan::build($events);
        $this->assertSame(array_values(array_map(static fn (RunEvent $e): int => $e->seq, array_filter($events, $reference->includes(...)))), $this->sequences($this->retainedLines()));
        $db = $this->db();
        try {
            $this->assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM command_record WHERE target_anchor IS NULL AND suppressed = 1'));
        } finally {
            $db->close();
        }
    }

    public function testMissingCorruptWrongVersionTruncatedAndReplacedIndexesRebuild(): void
    {
        $this->write(1, 1, 'turn_advanced');
        $this->write(5, 2, 'turn_advanced');
        $index = \dirname($this->path).'/history-index.sqlite';
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        (new Filesystem())->remove($index);
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        file_put_contents($index, 'not a database');
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $db = $this->db();
        $db->executeStatement('UPDATE index_meta SET version = 99');
        $db->close();
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $db = $this->db();
        $db->executeStatement('UPDATE index_meta SET boundary_offset = 0, boundary_length = end_offset');
        $db->close();
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $db = $this->db();
        $db->executeStatement('UPDATE event_location SET length = length + 1 WHERE seq = 1');
        $db->close();
        try {
            $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 1));
            $this->fail('Corrupt indexed length was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }
        $this->assertFileExists($index.'.unavailable');
        $this->assertSame([1, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        file_put_contents($this->path, '');
        $this->write(1, 1, 'turn_advanced');
        $this->assertSame([1], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        rename($this->path, $this->path.'.old');
        $this->write(9, 3, 'turn_advanced');
        $this->assertSame([9], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        // Same inode and length, changed boundary: identity checks alone are insufficient.
        $line = file_get_contents($this->path);
        file_put_contents($this->path, str_replace('"seq":9', '"seq":8', $line));
        $this->assertSame([8], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
    }

    public function testMissingSchemaObjectsAndEmptyPartialSchemaRebuildOnEveryConnection(): void
    {
        $this->write(1, 1, 'turn_advanced');
        $this->assertSame([1], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        foreach (['DROP INDEX turn_answers', 'DROP TABLE command_resolution', 'DROP TABLE retained_tool_invocation_location', 'DROP TABLE agent_submission_location', 'DROP TABLE presentation_state', 'DELETE FROM index_meta; DROP TABLE pending_prompt'] as $damage) {
            $db = $this->db();
            foreach (explode('; ', $damage) as $sql) {
                $db->executeStatement($sql);
            }
            $db->close();
            $this->logger->records = [];
            $this->assertSame([1], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
            $this->assertGreaterThan(0, $this->readBytes('index_cold_rebuild'));
            $db = $this->db();
            $this->assertSame(19, (int) $db->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'"));
            $db->close();
        }
    }

    public function testConnectionConfigurationIsAppliedWithoutBootstrappingExistingSchema(): void
    {
        $index = new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger);
        foreach ([1, 2] as $connection) {
            $db = (new \ReflectionMethod(RunHistoryIndex::class, 'connect'))->invoke($index, $this->path);
            try {
                (new \ReflectionMethod(RunHistoryIndex::class, 'configureConnection'))->invoke($index, $db);
                $this->assertSame(0, (int) $db->fetchOne('PRAGMA busy_timeout'));
                $this->assertSame(-1024, (int) $db->fetchOne('PRAGMA cache_size'));
                $this->assertSame(1, (int) $db->fetchOne('PRAGMA temp_store'));
                $this->assertSame('wal', $db->fetchOne('PRAGMA journal_mode'));
                $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM sqlite_master'));
            } finally {
                $db->close();
            }
        }
    }

    public function testDuplicateAndDecreasingSequencesNeverPublishWatermark(): void
    {
        foreach ([[1, 3, 3], [1, 5, 3]] as $sequences) {
            (new Filesystem())->remove([$this->path, \dirname($this->path).'/history-index.sqlite']);
            foreach ($sequences as $seq) {
                $this->write($seq, 1, 'turn_advanced');
            }
            try {
                $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100));
                $this->fail('Invalid canonical sequence order was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('unique increasing', $exception->getMessage());
            }
            $db = $this->db();
            try {
                $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM index_meta'));
            } finally {
                $db->close();
            }
        }
    }

    public function testColdIndexMemoryStaysBoundedInIndependent128MProcesses(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('history-index-memory');
        try {
            $measurements = [];
            foreach ([1000, 16000] as $count) {
                $process = new Process([\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/history-index-memory.php', $directory, (string) $count], \dirname(__DIR__, 3), ['HATFIELD_SESSION_ID' => false], timeout: 8);
                $process->mustRun();
                $measurements[] = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            }
            [$small, $large] = $measurements;
            $this->assertGreaterThan($small['archive_bytes'] * 15, $large['archive_bytes']);
            $this->assertGreaterThan($small['index_bytes'] * 5, $large['index_bytes']);
            $this->assertLessThan(128 * 1024 * 1024, $large['peak_bytes']);
            $this->assertLessThanOrEqual($small['peak_bytes'] + 4 * 1024 * 1024, $large['peak_bytes']);
            foreach ($measurements as $measurement) {
                // Cold recovery now reconstructs the bounded presentation too:
                // one scalar scan, two canonical presentation passes, one requested read.
                $this->assertSame($measurement['archive_bytes'] * 4, $measurement['archive_bytes_read']);
                $this->assertSame($measurement['count'], $measurement['records']);
            }
            fwrite(\STDERR, 'history-index memory: '.json_encode($measurements, \JSON_THROW_ON_ERROR)."\n");
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testBulkCommandPositionsPreserveLatestOccurrencesAcrossPageBoundary(): void
    {
        $this->write(1, 0, 'run_started');
        $keys = [];
        for ($i = 0; $i < 401; ++$i) {
            $key = 'bulk-'.$i;
            $keys[] = $key;
            $this->write(2 + $i, 0, 'agent_command_queued', ['kind' => 'steer', 'idempotency_key' => $key, 'text' => 'text']);
        }
        $this->write(403, 0, 'agent_command_rejected', ['kind' => 'steer', 'idempotency_key' => 'bulk-0', 'reason' => 'settled']);
        $this->write(404, 0, 'agent_command_queued', ['kind' => 'steer', 'idempotency_key' => 'bulk-1', 'text' => 'latest']);
        $this->assertSame(404, $this->log->historyCut($this->path, 'indexed')['sequence']);
        $positions = $this->log->latestCommandSequences($this->path, 'indexed', [...$keys, 'bulk-0', 'missing'], 404);
        $this->assertCount(401, $positions);
        $this->assertSame(403, $positions['bulk-0']);
        $this->assertSame(404, $positions['bulk-1']);
        $this->assertSame(402, $positions['bulk-400']);
        $this->assertArrayNotHasKey('missing', $positions);
        $this->assertSame([], $this->log->latestCommandSequences($this->path, 'indexed', [], 404));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('summaryTerminals')]
    public function testFirstSummaryAndOrdinaryPublicationUseOnlyBoundedPhysicalWork(string $reason, string $advanceReason): void
    {
        $this->path = \dirname($this->path).'/artifacts/agents/long-child/events.jsonl';
        (new Filesystem())->mkdir(\dirname($this->path));
        $events = [RunEvent::forAppend('indexed', 0, 'run_started', ['payload' => ['messages' => [$this->presentationMessage('user', 'Initial')]]]), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1]), RunEvent::forAppend('indexed', 1, 'history_position_set', ['position_turn_no' => 1, 'previous_position_turn_no' => null, 'reason' => 'continue'])];
        for ($i = 0; $i < 512; ++$i) {
            $events[] = RunEvent::forAppend('indexed', 1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Answer '.$i.' '.str_repeat('x', 8192))]);
        }
        $events[] = RunEvent::forAppend('indexed', 1, 'agent_end', ['reason' => $reason]);
        $start = hrtime(true);
        $this->publish($events);
        $initialMs = (hrtime(true) - $start) / 1_000_000;
        $this->logger->records = [];
        $reader = $this->presentationReader();
        $start = hrtime(true);
        $first = $reader->read('indexed', 0, 800);
        $summaryMs = (hrtime(true) - $start) / 1_000_000;
        $this->assertSame(0, $this->readLines('index_presentation_rebuild'));
        $this->assertSame(0, $this->readLines('indexed_range'));
        $this->assertSame(0, $this->readLines('index_cold_rebuild'));
        $this->assertSame(1, $this->readLines('index_boundary_validation'));
        $this->assertSame(513, $first->messageCount);
        $this->assertSame(516, $first->eventCount);
        $this->assertSame('failed' === $reason ? \Ineersa\AgentCore\Domain\Run\RunStatus::Failed : \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled, $first->status);
        $start = hrtime(true);
        $this->assertSummaryParity($reader);
        $canonicalMs = (hrtime(true) - $start) / 1_000_000;
        $this->logger->records = [];
        $publication = [];
        $suffixRecords = 0;
        for ($i = 0; $i < 16; ++$i) {
            $turn = $i + 2;
            $advance = [RunEvent::forAppend('indexed', $turn, 'turn_advanced', ['turn_no' => $turn]), RunEvent::forAppend('indexed', $turn, 'history_position_set', ['position_turn_no' => $turn, 'previous_position_turn_no' => $turn - 1, 'reason' => $advanceReason])];
            $completion = [RunEvent::forAppend('indexed', $turn, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Suffix '.$i)])];
            if ('shell_command' === $advanceReason) {
                $key = 'shell-'.$i;
                $id = 'sh_'.hash('sha256', $key);
                // ApplyShellCommandHandler seeds on the previous turn, then
                // publishes advance, confirmation and start in one transition.
                array_unshift($advance, RunEvent::forAppend('indexed', $turn - 1, 'agent_command_applied', ['kind' => 'shell_command', 'idempotency_key' => $key, 'text' => '!printf suffix', 'standalone' => true, 'current_operation' => ['turn_no' => $turn, 'step_id' => $key, 'attempt' => 1, 'idempotency_key' => $key]]));
                $advance[] = RunEvent::forAppend('indexed', $turn, 'tool_execution_start', ['tool_call_id' => $id, 'tool_name' => 'bash', 'order_index' => 0, 'attempt' => 1, 'arguments' => ['command' => 'printf suffix']]);
                $codec = static::getContainer()->get(ToolExecutionEndPayloadCodec::class);
                $completion = [RunEvent::forAppend('indexed', $turn, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult('indexed', $turn, $key, 1, $key, $id, 0, ['tool_name' => 'bash', 'output' => 'suffix']))), RunEvent::forAppend('indexed', $turn, 'agent_end', ['reason' => 'completed'])];
            }
            foreach ([$advance, $completion] as $transition) {
                $start = hrtime(true);
                $this->publish($transition);
                $publication[] = (hrtime(true) - $start) / 1_000_000;
                $suffixRecords += \count($transition);
                $this->assertSummaryParity($reader);
            }
        }
        fwrite(\STDERR, 'production presentation publication: '.json_encode(['reason' => $reason, 'advance_reason' => $advanceReason, 'archive_bytes' => filesize($this->path), 'initial_publication_ms' => $initialMs, 'first_summary_ms' => $summaryMs, 'canonical_parity_ms' => $canonicalMs, 'transition_publication_ms' => $publication, 'suffix_records' => $suffixRecords, 'presentation_rebuild_lines' => $this->readLines('index_presentation_rebuild'), 'presentation_rebuild_bytes' => $this->readBytes('index_presentation_rebuild')], \JSON_THROW_ON_ERROR)."\n");
        $this->assertSame($suffixRecords, $this->readLines('index_suffix_catch_up'));
        $this->assertSame(0, $this->readLines('index_presentation_rebuild'));
        $this->assertSame(0, $this->readLines('index_cold_rebuild'));
        $this->assertSummaryParity($reader);
        $db = $this->db();
        try {
            $stateBytes = (int) $db->fetchOne('SELECT length(state_json) FROM presentation_state');
            $this->assertLessThan(4096, $stateBytes);
            $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM presentation_state'));
        } finally {
            $db->close();
        }
    }

    public static function summaryTerminals(): iterable
    {
        foreach (['failed', 'cancelled'] as $terminal) {
            foreach (['continue', 'shell_command'] as $advance) {
                yield $terminal.'_'.$advance => [$terminal, $advance];
            }
        }
    }

    public function testActualNavigationStillRebuildsAndInvalidatesFollowingAdvance(): void
    {
        $reader = $this->presentationReader();
        $this->publish([RunEvent::forAppend('indexed', 0, 'run_started'), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1]), RunEvent::forAppend('indexed', 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'continue']), RunEvent::forAppend('indexed', 1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Retained')]), RunEvent::forAppend('indexed', 2, 'turn_advanced', ['turn_no' => 2]), RunEvent::forAppend('indexed', 2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'continue']), RunEvent::forAppend('indexed', 2, 'agent_end', ['reason' => 'completed']), RunEvent::forAppend('indexed', 2, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->presentationMessage('user', 'Restored seed')])]);
        $cases = [
            'same-position selection' => [true, true, [[2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'history_select']]]],
            'confirmation preserves pending navigation' => [false, true, [[2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'shell_command']]]],
            'advance restores suppressed seed' => [true, false, [[3, 'turn_advanced', ['turn_no' => 3]], [3, 'history_position_set', ['position_turn_no' => 3, 'reason' => 'continue']]]],
            'subsequent ordinary advance' => [false, false, [[4, 'turn_advanced', ['turn_no' => 4]], [4, 'history_position_set', ['position_turn_no' => 4, 'reason' => 'continue']]]],
            'continue marker actually changes selection' => [true, true, [[1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'continue']]]],
            'same-position discard' => [true, true, [[1, 'history_tail_discarded', ['after_turn_no' => 1]]]],
            'advance after changed selection' => [true, false, [[5, 'turn_advanced', ['turn_no' => 5]], [5, 'history_position_set', ['position_turn_no' => 5, 'reason' => 'continue']]]],
            'discard' => [true, true, [[1, 'history_tail_discarded', ['after_turn_no' => 1]]]],
            'displayed turn reintroduction' => [true, false, [[2, 'turn_advanced', ['turn_no' => 2]], [2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'continue']]]],
        ];
        foreach ($cases as $label => [$rebuild, $pending, $specs]) {
            $this->logger->records = [];
            $this->publish(array_map(static fn (array $spec): RunEvent => RunEvent::forAppend('indexed', ...$spec), $specs));
            $this->assertSame($rebuild, $this->readLines('index_presentation_rebuild') > 0, $label);
            $this->assertSummaryParity($reader);
            $db = $this->db();
            try {
                $state = json_decode($db->fetchOne('SELECT state_json FROM presentation_state'), true, flags: \JSON_THROW_ON_ERROR);
                $this->assertSame($pending, $state['navigation_pending'], $label);
            } finally {
                $db->close();
            }
            if ('same-position selection' === $label) {
                $this->assertSame(1, $reader->read('indexed', 0, 800)->messageCount);
            } elseif ('advance restores suppressed seed' === $label) {
                $this->assertSame(2, $reader->read('indexed', 0, 800)->messageCount);
            }
        }
    }

    public function testSummarySurvivesNavigationCompactionRefreshAndRetroactiveSeedMapping(): void
    {
        $reader = $this->presentationReader();
        $fixtures = [
            [0, 'run_started', ['payload' => ['messages' => [$this->presentationMessage('system', 'System'), $this->presentationMessage('user', 'Initial')]]]],
            [1, 'turn_advanced', ['turn_no' => 1]],
            [1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Retained')]],
            [1, 'agent_end', ['reason' => 'completed']],
            [1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->presentationMessage('user', 'Restored seed')]],
            [1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']],
            [2, 'turn_advanced', ['turn_no' => 2]],
            [2, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Forward secret')]],
            [1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']],
            [1, 'history_tail_discarded', ['after_turn_no' => 1]],
            [3, 'turn_advanced', ['turn_no' => 3]],
            [3, 'context_compacted', ['messages' => [$this->presentationMessage('system', 'Compacted'), $this->presentationMessage('user', 'Checkpoint'), $this->presentationMessage('assistant', 'Checkpoint answer')]]],
            [3, 'context_refreshed', ['messages' => [$this->presentationMessage('system', 'Refreshed'), ['role' => 'user-context', 'content' => [['type' => 'text', 'text' => 'Generated']], 'metadata' => ['source' => 'agents_context']]]]],
            [3, 'waiting_human', ['question_id' => 'one']],
            [3, 'waiting_human', ['question_id' => 'two']],
            [3, 'agent_command_applied', ['kind' => 'human_response', 'question_id' => 'one', 'message' => $this->presentationMessage('user', 'Yes')]],
            [3, 'agent_command_applied', ['kind' => 'human_response', 'question_id' => 'two', 'message' => $this->presentationMessage('user', 'No')]],
            [3, 'context_compaction_started', ['step_id' => 'compact']],
            [3, 'context_compaction_failed', ['step_id' => 'compact', 'continue_after_compaction' => false]],
            [3, 'agent_end', ['reason' => 'cancelled']],
        ];
        foreach ($fixtures as [$turn, $type, $payload]) {
            $this->publish([RunEvent::forAppend('indexed', $turn, $type, $payload)]);
            $this->assertSummaryParity($reader);
        }
        $this->assertStringNotContainsString('Forward secret', $reader->read('indexed', 0, 800)->assistantExcerpt);
    }

    public function testSummaryRestoresPendingToolShellAndQuestionAccumulators(): void
    {
        $codec = static::getContainer()->get(ToolExecutionEndPayloadCodec::class);
        $assistant = $this->presentationMessage('assistant', 'Partial');
        $assistant['tool_calls'] = [['id' => 'ordinary', 'function' => ['name' => 'read', 'arguments' => '{}']]];
        $this->publish([RunEvent::forAppend('indexed', 0, 'run_started'), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1]), RunEvent::forAppend('indexed', 1, 'llm_step_completed', ['assistant_message' => $assistant]), RunEvent::forAppend('indexed', 1, 'agent_command_applied', ['kind' => 'shell_command', 'idempotency_key' => 'shell'])]);
        foreach (['ordinary', 'sh_'.hash('sha256', 'shell')] as $id) {
            $this->publish([RunEvent::forAppend('indexed', 1, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult('indexed', 1, 'step', 1, 'key', $id, 0, ['tool_name' => 'read', 'output' => 'output'])))]);
            $this->assertSummaryParity($this->presentationReader());
        }
        $this->publish([RunEvent::forAppend('indexed', 1, 'waiting_human', ['question_id' => 'question'])]);
        $this->assertSummaryParity($this->presentationReader());
        $this->publish([RunEvent::forAppend('indexed', 1, 'agent_command_applied', ['kind' => 'human_response', 'question_id' => 'question', 'message' => $this->presentationMessage('user', 'Proceed')]), RunEvent::forAppend('indexed', 1, 'agent_end', ['reason' => 'failed'])]);
        $this->assertSummaryParity($this->presentationReader());
        $this->assertSame(1, $this->presentationReader()->read('indexed', 0, 800)->pendingToolCallCount);
        $this->publish([RunEvent::forAppend('indexed', 1, 'tool_batch_committed')]);
        $this->assertSummaryParity($this->presentationReader());
        $this->assertSame(0, $this->presentationReader()->read('indexed', 0, 800)->pendingToolCallCount);
    }

    public function testSummaryRecoveryAndUnpublishedJournalCutoff(): void
    {
        $this->publish([RunEvent::forAppend('indexed', 0, 'run_started'), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1]), RunEvent::forAppend('indexed', 1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Committed')])]);
        $reader = $this->presentationReader();
        $expected = $reader->read('indexed', 0, 800);
        foreach (['missing_projection', 'bad_hash', 'bad_shape', 'stale_projection', 'stale_schema', 'missing_index', 'corrupt_index'] as $fault) {
            if ('missing_index' === $fault || 'corrupt_index' === $fault) {
                (new Filesystem())->remove([\dirname($this->path).'/history-index.sqlite', \dirname($this->path).'/history-index.sqlite-wal', \dirname($this->path).'/history-index.sqlite-shm']);
                if ('corrupt_index' === $fault) {
                    file_put_contents(\dirname($this->path).'/history-index.sqlite', 'not sqlite');
                }
            } else {
                $db = $this->db();
                try {
                    if ('missing_projection' === $fault) {
                        $db->executeStatement('DELETE FROM presentation_state');
                    } elseif ('bad_hash' === $fault) {
                        $db->executeStatement("UPDATE presentation_state SET state_hash = 'wrong'");
                    } elseif ('stale_schema' === $fault) {
                        $db->executeStatement('UPDATE index_meta SET version = 6');
                    } else {
                        $state = json_decode($db->fetchOne('SELECT state_json FROM presentation_state'), true, flags: \JSON_THROW_ON_ERROR);
                        if ('bad_shape' === $fault) {
                            $state['projection']['messages']['messageCount'] = [];
                        } else {
                            --$state['sequence'];
                        }
                        $json = json_encode($state, \JSON_THROW_ON_ERROR);
                        $db->executeStatement('UPDATE presentation_state SET state_json = ?, state_hash = ?', [$json, hash('sha256', $json)]);
                    }
                } finally {
                    $db->close();
                }
            }
            $this->logger->records = [];
            $this->assertEquals($expected, $reader->read('indexed', 0, 800), $fault);
            $this->assertGreaterThan(0, $this->readLines('index_presentation_rebuild'), $fault);
        }
        $this->logger->records = [];
        $persisted = $this->log->appendMany($this->path, [RunEvent::forAppend('indexed', 1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Unpublished')])], work: ['run_id' => 'indexed', 'predecessor_seq' => $this->publishedSequence]);
        $this->assertEquals($expected, $reader->read('indexed', 0, 800));
        $verified = $this->log->verifiedPendingTransition($this->path, 'indexed');
        $this->assertNotNull($verified);
        $this->log->finalizeVerifiedTransition($this->path, 'indexed', $verified);
        $this->publishedSequence = $persisted[0]->seq;
        $this->assertSame('Unpublished', $reader->read('indexed', 0, 800)->assistantExcerpt);
        $this->assertSame(0, $this->readLines('index_presentation_rebuild'));
    }

    public function testPresentationFailureDoesNotInvalidateScalarIndexAndCanBeRetired(): void
    {
        $this->publish([RunEvent::forAppend('indexed', 0, 'run_started'), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1]), RunEvent::forAppend('indexed', 1, 'agent_end', ['reason' => 'completed']), RunEvent::forAppend('indexed', 2, 'turn_advanced', ['turn_no' => 2]), RunEvent::forAppend('indexed', 2, 'tool_execution_end', ['tool_result' => ['tool_call_id' => 'malformed']])]);
        $this->assertSame(5, $this->log->historyCut($this->path, 'indexed')['sequence']);
        $this->assertSame([1, 2, 3, 4, 5], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 5)));
        $this->assertNotEmpty(array_filter($this->logger->records, static fn (array $record): bool => 'history_index.presentation_failed' === $record['message']));
        try {
            $this->presentationReader()->read('indexed', 0, 800);
            $this->fail('Malformed selected result was silently accepted.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('verified presentation', $exception->getMessage());
        }
        $this->publish([RunEvent::forAppend('indexed', 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']), RunEvent::forAppend('indexed', 1, 'history_tail_discarded', ['after_turn_no' => 1])]);
        $this->assertSummaryParity($this->presentationReader());
    }

    public function testInvisibleSchemaTailDoesNotInvalidateTheVisibleSummaryCut(): void
    {
        $this->publish([RunEvent::forAppend('indexed', 0, 'run_started'), RunEvent::forAppend('indexed', 1, 'turn_advanced', ['turn_no' => 1])]);
        $expected = $this->presentationReader()->read('indexed', 0, 800);
        $normalizer = static::getContainer()->get(EventPayloadNormalizer::class);
        $record = $normalizer->normalize('indexed', 3, 1, 'llm_step_completed', ['assistant_message' => $this->presentationMessage('assistant', 'Invisible')]);
        $record['schema_version'] = '999.0';
        file_put_contents($this->path, json_encode($record, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
        (new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger))->updateAfterCommit($this->log, $this->path, 'indexed');
        $this->assertEquals($expected, $this->presentationReader()->read('indexed', 0, 800));
        $this->assertSame(3, $this->log->historyCut($this->path, 'indexed')['sequence']);
    }

    /** @param list<RunEvent> $events */
    private function publish(array $events): void
    {
        $persisted = $this->log->appendMany($this->path, $events, work: ['run_id' => 'indexed', 'predecessor_seq' => $this->publishedSequence]);
        $verified = $this->log->verifiedPendingTransition($this->path, 'indexed');
        $this->assertNotNull($verified);
        $this->log->finalizeVerifiedTransition($this->path, 'indexed', $verified);
        $this->publishedSequence = $persisted[array_key_last($persisted)]->seq;
    }

    private function presentationReader(): RunPresentationReader
    {
        $events = $this->createStub(EventStoreInterface::class);
        $events->method('latestSequenceFor')->willReturnCallback(fn (): int => $this->publishedSequence);
        $events->method('rangeFor')->willReturnCallback(function (string $runId, int $start, int $end): iterable {
            foreach ($this->log->indexedLines($this->path, $runId, $start, $end) as $line) {
                $event = $this->log->denormalizeRunEvent($this->log->decodeLine($line));
                if (null !== $event) {
                    yield $event;
                }
            }
        });
        $sources = $this->createStub(RunHistorySourceProviderInterface::class);
        $sources->method('historySource')->willReturn(new RunHistorySourceDTO($this->log, $this->path));

        return new RunPresentationReader($events, static::getContainer()->get(RunLockManager::class), new ReplayAssistantMessageFactory(), static::getContainer()->get(ToolExecutionEndPayloadCodec::class), $sources);
    }

    private function assertSummaryParity(RunPresentationReader $reader): void
    {
        $expected = get_object_vars($reader->read('indexed', 1, 800));
        $actual = get_object_vars($reader->read('indexed', 0, 800));
        unset($expected['historyLines'], $actual['historyLines']);
        $this->assertSame($expected, $actual);
    }

    /** @return array{role: string, content: list<array{type: string, text: string}>} */
    private function presentationMessage(string $role, string $text): array
    {
        return ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return iterable<string> */
    private function retainedLines(): iterable
    {
        return (new RunHistoryIndex(static::getContainer()->get(LockFactory::class), $this->logger))->records($this->log, $this->path, 'indexed', 1, \PHP_INT_MAX, retained: true);
    }

    private function db(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => \dirname($this->path).'/history-index.sqlite']);
    }

    /** @param array<string, mixed> $payload */
    private function write(int $seq, int $turn, string $type, array $payload = []): int
    {
        $normalizer = static::getContainer()->get(EventPayloadNormalizer::class);
        $line = json_encode($normalizer->normalize('indexed', $seq, $turn, $type, $payload), \JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->path, $line, \FILE_APPEND);

        return \strlen($line);
    }

    /** @param iterable<string> $lines
     * @return list<int> */
    private function sequences(iterable $lines): array
    {
        $sequences = [];
        foreach ($lines as $line) {
            $sequences[] = json_decode($line, true, 512, \JSON_THROW_ON_ERROR)['seq'];
        }

        return $sequences;
    }

    private function readBytes(string $reason): int
    {
        return array_sum(array_map(static fn (array $r): int => $r['context']['archive_bytes_read'], array_filter($this->logger->records, static fn (array $r): bool => $reason === ($r['context']['read_reason'] ?? null))));
    }

    private function readLines(string $reason): int
    {
        return array_sum(array_map(static fn (array $r): int => $r['context']['lines_yielded'], array_filter($this->logger->records, static fn (array $r): bool => $reason === ($r['context']['read_reason'] ?? null))));
    }
}
