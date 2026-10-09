<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\History\HistoryReplayPlan;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->log = new JsonlRunEventLog(static::getContainer()->get(EventPayloadNormalizer::class), static::getContainer()->get(LockFactory::class), static::getContainer()->get(RunSequenceAllocatorInterface::class), new EventLogMaxSeqBootstrapReader(), $this->logger);
        $this->path = getcwd().'/.hatfield/sessions/indexed/events.jsonl';
        (new Filesystem())->mkdir(\dirname($this->path));
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
        $this->log->finalizeVerifiedTransition($this->path, 'indexed', $pending->identity);
        $this->assertSame([1], $this->sequences($this->log->indexedLines($this->path, 'indexed', 1, 100)));
        $db = $this->db();
        $db->beginTransaction();
        $db->executeStatement('UPDATE index_meta SET version = version');
        try {
            $this->log->appendMany($this->path, [RunEvent::forAppend('indexed', 1, 'turn_advanced')], work: ['run_id' => 'indexed', 'predecessor_seq' => 1]);
            $pending = $this->log->verifiedPendingTransition($this->path, 'indexed');
            $this->assertNotNull($pending);
            $this->log->finalizeVerifiedTransition($this->path, 'indexed', $pending->identity);
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
                $this->assertSame($measurement['archive_bytes'] * 2, $measurement['archive_bytes_read']);
                $this->assertSame($measurement['count'], $measurement['records']);
            }
            fwrite(\STDERR, 'history-index memory: '.json_encode($measurements, \JSON_THROW_ON_ERROR)."\n");
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
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
