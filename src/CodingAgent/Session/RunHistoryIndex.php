<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Ineersa\AgentCore\Application\Replay\ReplayLifecycleStatus;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\DTO\PendingToolInvocationDTO;
use Ineersa\CodingAgent\Session\History\HistoryAssistantTextExtractor;
use Ineersa\CodingAgent\Session\History\HistoryPromptTextExtractor;
use Ineersa\CodingAgent\Session\History\HistoryReplayPlan;
use Ineersa\CodingAgent\Session\History\RunPresentationDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/** Disposable scalar locations. Canonical bodies and growing history mappings never live here in PHP. */
final readonly class RunHistoryIndex
{
    public const int PAGE_SIZE = 32;
    public const int PREVIEW_BYTES = 240;
    private const int VERSION = 9;

    public function __construct(private LockFactory $locks, private LoggerInterface $logger)
    {
    }

    /** Called under the canonical storage lock, only after verified finalization. */
    public function updateAfterCommit(JsonlRunEventLog $log, string $path, string $runId): void
    {
        try {
            $db = $this->synchronize($log, $path, $runId);
            $db->close();
        } catch (\Throwable $exception) {
            // Index failure cannot undo canonical publication. Historical readers must
            // rebuild this disposable file before using it again, never trust stale rows.
            $this->logger->warning('history_index.publication_failed', $this->context($runId) + ['exception_class' => $exception::class]);
            $this->markUnavailable($path, $runId);
        }
    }

    /** @return array{sequence: int, end_offset: int, anchor: int}|null */
    public function cut(JsonlRunEventLog $log, string $path, string $runId, ?int $positionTurnNo = null): ?array
    {
        if (null !== $positionTurnNo && $positionTurnNo < 0) {
            throw new \InvalidArgumentException('History position must be non-negative.');
        }
        if (!is_file($path)) {
            return null;
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            $meta = $db->fetchAssociative('SELECT * FROM index_meta');
            if (false === $meta || 0 === (int) $meta['last_seq']) {
                return null;
            }
            $anchor = null === $positionTurnNo ? (int) $meta['selected_anchor'] : $this->anchorForTurn($db, $positionTurnNo);
            if (null !== $positionTurnNo && $positionTurnNo > 0 && 0 === $anchor) {
                throw new \InvalidArgumentException('Requested history position is not retained.');
            }

            return ['sequence' => (int) $meta['last_seq'], 'end_offset' => (int) $meta['end_offset'], 'anchor' => $anchor];
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    /** @return array{position: int, predecessor: int, text: string, selectedPromptTurnNo: int} */
    public function selectEntry(JsonlRunEventLog $log, string $path, string $runId, int $turnNo, string $role = 'user'): array
    {
        if (!\in_array($role, ['user', 'assistant'], true)) {
            throw new \InvalidArgumentException('History selection requires a user or assistant role.');
        }
        if (!is_file($path)) {
            throw new \RuntimeException('Cannot select history: no events found.');
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        $observation = new JsonlPhysicalReadObservation();
        try {
            $db = $this->synchronize($log, $path, $runId);
            $position = (int) $db->fetchOne('SELECT t.turn_no FROM index_meta m LEFT JOIN turn_anchor t ON t.anchor = m.selected_anchor');
            $selectedPromptTurnNo = $turnNo;
            if ('assistant' === $role) {
                $answer = $db->fetchAssociative('SELECT anchor FROM turn_anchor WHERE turn_no = ? AND retained = 1 AND answer_seq IS NOT NULL ORDER BY anchor DESC LIMIT 1', [$turnNo]);
                if (false === $answer) {
                    throw new \RuntimeException('Target is not a selectable final assistant response.');
                }
                // Displayed turn numbers are sparse and can run backwards. Find the
                // next human prompt in immutable retained anchor order instead.
                $next = $db->fetchOne('SELECT turn_no FROM turn_anchor WHERE retained = 1 AND prompt_seq IS NOT NULL AND anchor > ? ORDER BY anchor LIMIT 1', [$answer['anchor']]);
                if (false === $next) {
                    return ['position' => $position, 'predecessor' => $turnNo, 'text' => '', 'selectedPromptTurnNo' => 0];
                }
                $selectedPromptTurnNo = (int) $next;
            }
            $row = $db->fetchAssociative('SELECT t.predecessor, e.seq, e.offset, e.length, e.type FROM turn_anchor t JOIN event_location e ON e.seq = t.prompt_seq WHERE t.turn_no = ? AND t.retained = 1 ORDER BY t.anchor DESC LIMIT 1', [$selectedPromptTurnNo]);
            if (false === $row) {
                throw new \RuntimeException(\sprintf('Cannot select history for run %s: target turn %d is not a selectable human prompt.', $runId, $turnNo));
            }
            $predecessor = 'assistant' === $role ? $turnNo : (int) $db->fetchOne('SELECT turn_no FROM turn_anchor WHERE anchor = ?', [$row['predecessor']]);
            try {
                $text = '';
                foreach ($log->linesAt($path, [['offset' => (int) $row['offset'], 'length' => (int) $row['length']]], $observation) as $line) {
                    $record = $log->decodeLine($line);
                    if (!\is_array($record) || ($record['run_id'] ?? null) !== $runId || ($record['seq'] ?? null) !== (int) $row['seq'] || ($record['type'] ?? null) !== $row['type'] || !\is_array($record['payload'] ?? null)) {
                        throw new \RuntimeException('Indexed prompt does not match its canonical event.');
                    }
                    $text = HistoryPromptTextExtractor::extract($record['type'], $record['payload']);
                }
                if ('' === $text) {
                    throw new \RuntimeException('Indexed prompt has no canonical human text.');
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('history_index.prompt_read_failed', $this->context($runId) + ['exception_class' => $exception::class]);
                $this->markUnavailable($path, $runId);
                throw $exception;
            }

            return ['position' => $position, 'predecessor' => $predecessor, 'text' => $text, 'selectedPromptTurnNo' => $selectedPromptTurnNo];
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
            $this->observe($runId, 'selected_prompt', $observation);
        }
    }

    /**
     * @return array{rows: list<array{anchor: int, cursor: int, turn_no: int, preview: string, role: string}>, selected: int, selected_role: string, older: ?int, newer: ?int}
     */
    public function entryPage(JsonlRunEventLog $log, string $path, string $runId, ?int $before = null, ?int $after = null): array
    {
        if (null !== $before && null !== $after) {
            throw new \InvalidArgumentException('A history page has only one direction.');
        }
        $empty = ['rows' => [], 'selected' => 0, 'selected_role' => 'user', 'older' => null, 'newer' => null];
        if (!is_file($path)) {
            return $empty;
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            $selected = (int) $db->fetchOne('SELECT selected_anchor FROM index_meta');
            $selectedRole = false !== $db->fetchOne('SELECT anchor FROM turn_anchor WHERE anchor = ? AND answer_seq IS NOT NULL', [$selected]) ? 'assistant' : 'user';
            $entries = "(SELECT anchor, anchor AS cursor, turn_no, prompt_preview AS preview, 'user' AS role FROM turn_anchor WHERE retained = 1 AND prompt_seq IS NOT NULL UNION ALL SELECT anchor, answer_seq AS cursor, turn_no, answer_preview AS preview, 'assistant' AS role FROM turn_anchor WHERE retained = 1 AND answer_seq IS NOT NULL)";
            $sql = 'SELECT * FROM '.$entries;
            $descending = null !== $before;
            $params = [];
            if (null !== $before) {
                $sql .= ' WHERE cursor < ?';
                $params[] = $before;
            } elseif (null !== $after) {
                $sql .= ' WHERE cursor > ?';
                $params[] = $after;
            } else {
                $focus = $db->fetchOne('SELECT anchor FROM turn_anchor WHERE retained = 1 AND prompt_seq IS NOT NULL AND anchor > ? ORDER BY anchor LIMIT 1', [$selected]);
                if (false !== $focus) {
                    $sql .= ' WHERE cursor >= ?';
                    $params[] = $focus;
                } else {
                    $descending = true;
                }
            }
            $result = $db->executeQuery($sql.' ORDER BY cursor '.($descending ? 'DESC' : 'ASC').' LIMIT '.self::PAGE_SIZE, $params);
            $rows = [];
            while (false !== ($row = $result->fetchAssociative())) {
                $rows[] = ['anchor' => (int) $row['anchor'], 'cursor' => (int) $row['cursor'], 'turn_no' => (int) $row['turn_no'], 'preview' => (string) $row['preview'], 'role' => (string) $row['role']];
            }
            $result->free();
            if ($descending) {
                $rows = array_reverse($rows);
            }
            if ([] === $rows) {
                return $empty;
            }
            $first = $rows[0]['cursor'];
            $last = $rows[array_key_last($rows)]['cursor'];
            $older = false !== $db->fetchOne('SELECT cursor FROM '.$entries.' WHERE cursor < ? LIMIT 1', [$first]);
            $newer = false !== $db->fetchOne('SELECT cursor FROM '.$entries.' WHERE cursor > ? LIMIT 1', [$last]);

            return ['rows' => $rows, 'selected' => $selected, 'selected_role' => $selectedRole, 'older' => $older ? $first : null, 'newer' => $newer ? $last : null];
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    /** Scalar retained-order evidence; displayed turn numbers are not an ordering. */
    public function hasForwardTail(JsonlRunEventLog $log, string $path, string $runId, int $positionTurnNo): bool
    {
        if ($positionTurnNo < 0 || !is_file($path)) {
            return false;
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            $anchor = $this->anchorForTurn($db, $positionTurnNo);
            // A stale/non-retained state must not discard surviving history.
            if ($positionTurnNo > 0 && 0 === $anchor) {
                return false;
            }

            return false !== $db->fetchOne('SELECT anchor FROM turn_anchor WHERE retained = 1 AND anchor > ? LIMIT 1', [$anchor]);
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    /** Lookup within the index already validated by this owner's selected replay.
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    public function latestCommandSequences(string $path, string $runId, array $keys, int $sequence): array
    {
        if ([] === $keys) {
            return [];
        }
        if (is_file($this->indexPath($path).'.unavailable')) {
            throw new \RuntimeException('Resume command evidence requires a usable history index.');
        }
        $db = $this->connect($path);
        try {
            $meta = $db->fetchAssociative('SELECT version, run_id, last_seq FROM index_meta');
            if (false === $meta || self::VERSION !== (int) $meta['version'] || $meta['run_id'] !== $runId || (int) $meta['last_seq'] < $sequence) {
                throw new \RuntimeException('Resume command evidence does not match its indexed cut.');
            }

            $positions = [];
            foreach (array_chunk(array_values(array_unique($keys)), 400) as $chunk) {
                $rows = $db->fetchAllKeyValue(
                    'SELECT identity_hash, sequence FROM command_resolution WHERE identity_hash IN (?)',
                    [array_map(static fn (string $key): string => hash('sha256', $key), $chunk)],
                    [ArrayParameterType::STRING],
                );
                foreach ($chunk as $key) {
                    $hash = hash('sha256', $key);
                    if (isset($rows[$hash])) {
                        $positions[$key] = (int) $rows[$hash];
                    }
                }
            }

            return $positions;
        } finally {
            $db->close();
        }
    }

    /** @return \Generator<int, string> */
    public function records(JsonlRunEventLog $log, string $path, string $runId, int $startSeq, int $endSeq, ?int $anchor = null, bool $retained = false): \Generator
    {
        foreach ($this->locatedRecords($log, $path, $runId, $startSeq, $endSeq, $anchor, $retained) as [$line]) {
            yield $line;
        }
    }

    /** @return \Generator<int, array{string, array<string, mixed>}> */
    public function locatedRecords(JsonlRunEventLog $log, string $path, string $runId, int $startSeq, int $endSeq, ?int $anchor = null, bool $retained = false): \Generator
    {
        if (!is_file($path)) {
            return;
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId, $retained ? null : $endSeq);
            $meta = $db->fetchAssociative('SELECT * FROM index_meta');
            if (false === $meta) {
                throw new \RuntimeException('History index has no committed watermark.');
            }
            $endSeq = min($endSeq, (int) $meta['last_seq']);
            $anchor ??= (int) $meta['selected_anchor'];
            if ($retained && $anchor > 0 && false === $db->fetchOne('SELECT anchor FROM turn_anchor WHERE anchor = ? AND retained = 1', [$anchor])) {
                throw new \InvalidArgumentException('History anchor is not retained.');
            }
            $sql = 'SELECT e.seq, e.offset, e.length, e.type FROM event_location e WHERE e.seq BETWEEN ? AND ?';
            $params = [$startSeq, $endSeq];
            if ($retained) {
                // Ancestor traversal and command resolution stay in SQLite, not a PHP
                // retainedTurnNos/seen-sequence set. History controls are always visible.
                $sql = 'WITH RECURSIVE prefix(anchor) AS (SELECT ? WHERE ? > 0 UNION ALL SELECT t.predecessor FROM turn_anchor t JOIN prefix p ON t.anchor = p.anchor WHERE t.predecessor > 0) '.$sql;
                $params = [$anchor, $anchor, $startSeq, $endSeq];
                $sql .= " AND (e.turn_no = 0 OR e.type IN ('history_position_set', 'history_tail_discarded') OR (e.anchor IN (SELECT anchor FROM prefix) AND NOT EXISTS (SELECT 1 FROM command_record c WHERE c.seq = e.seq AND (c.suppressed = 1 OR (c.target_anchor IS NOT NULL AND c.target_anchor NOT IN (SELECT anchor FROM prefix))))))";
            }
            $result = $db->executeQuery($sql.' ORDER BY e.seq', $params);
        } catch (\Throwable $exception) {
            if (isset($db)) {
                $db->close();
            }
            throw $exception;
        } finally {
            $lock->release();
        }

        $observation = new JsonlPhysicalReadObservation();
        $expected = null;
        $locations = (static function () use ($result, &$expected): \Generator {
            while (false !== ($row = $result->fetchAssociative())) {
                $expected = $row;
                yield ['offset' => (int) $row['offset'], 'length' => (int) $row['length']];
            }
        })();
        try {
            foreach ($log->linesAt($path, $locations, $observation) as $line) {
                $record = $log->decodeLine($line);
                if (!\is_array($record) || ($record['run_id'] ?? null) !== $runId || ($record['seq'] ?? null) !== (int) $expected['seq'] || ($record['type'] ?? null) !== $expected['type']) {
                    throw new \RuntimeException('Indexed location does not match its canonical event.');
                }
                yield [$line, $record];
                unset($record, $line);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('history_index.record_read_failed', $this->context($runId) + ['exception_class' => $exception::class]);
            $this->markUnavailable($path, $runId);
            throw $exception;
        } finally {
            $result->free();
            $db->close();
            $this->observe($runId, $retained ? 'selected_history' : 'sequence_range', $observation);
        }
    }

    public function pendingToolInvocation(JsonlRunEventLog $log, string $path, string $runId, string $toolCallId, bool $requireCurrentStep = true): ?PendingToolInvocationDTO
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Invocation admission requires canonical history.');
        }
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            if ((int) $db->fetchOne('SELECT last_seq FROM index_meta') < 1) {
                throw new \RuntimeException('Invocation admission requires committed canonical history.');
            }
            // The selected predecessor chain is materialized on history changes.
            // Anchor intervals cannot establish ancestry across retained branches.
            $table = $requireCurrentStep ? 'tool_invocation_location' : 'retained_tool_invocation_location';
            $stepFilter = $requireCurrentStep ? '(i.shell = 1 OR (i.turn_no = (SELECT turn_no FROM admission_step_location) AND (i.step_id IS NULL OR i.step_id = (SELECT step_id FROM admission_step_location))))' : '1 = 1';
            $row = $db->fetchAssociative('SELECT i.* FROM '.$table.' i
                JOIN event_location e ON e.seq = i.seq LEFT JOIN turn_anchor t ON t.anchor = e.anchor
                LEFT JOIN command_record c ON c.seq = e.seq LEFT JOIN turn_anchor target ON target.anchor = c.target_anchor
                WHERE i.tool_call_id = ? AND '.$stepFilter.'
                AND (e.anchor = 0 OR (t.retained = 1 AND EXISTS (SELECT 1 FROM admission_anchor a WHERE a.anchor = e.anchor)))
                AND (c.seq IS NULL OR (c.suppressed = 0 AND (c.target_anchor IS NULL OR (target.retained = 1 AND EXISTS (SELECT 1 FROM admission_anchor a WHERE a.anchor = c.target_anchor)))))', [$toolCallId]);
            if (false === $row) {
                return null;
            }
            if (!\is_string($row['step_id']) || '' === $row['step_id'] || (int) $row['attempt'] < 1) {
                throw new \RuntimeException('Active canonical invocation has invalid identity metadata.');
            }

            return new PendingToolInvocationDTO((int) $row['turn_no'], $row['step_id'], (int) $row['attempt'], (bool) $row['shell'], (bool) $row['standalone']);
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    public function submissionSequence(JsonlRunEventLog $log, string $path, string $runId, string $identity): ?int
    {
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            $sequence = $db->fetchOne('SELECT seq FROM agent_submission_location WHERE identity_hash = ?', [hash('sha256', $identity)]);

            return false === $sequence ? null : (int) $sequence;
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    public function presentationSummary(JsonlRunEventLog $log, string $path, string $runId, int $sequence): RunPresentationDTO
    {
        $lock = $this->locks->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $db = $this->synchronize($log, $path, $runId);
            $watermark = (int) $db->fetchOne('SELECT last_seq FROM index_meta');
            $state = $this->loadPresentation($db, $runId, $watermark);
            // Invisible schema versions occupy physical index locations but do
            // not extend the presentation reader's visible canonical cut.
            if (null === $state || null === $state['projection'] || $sequence > $watermark || $state['projection']['lastObservedSequence'] > $sequence) {
                throw new \UnexpectedValueException('Canonical history cannot provide a verified presentation summary.');
            }
            $projector = $log->presentationProjector();
            $projector->restore($state['projection']);

            return $projector->presentation($state['turn'], $sequence);
        } finally {
            if (isset($db)) {
                $db->close();
            }
            $lock->release();
        }
    }

    /** @return array{sequence: int, turn: int, navigation_pending: bool, projection: array<string, mixed>|null}|null */
    private function loadPresentation(Connection $db, string $runId, int $sequence): ?array
    {
        $row = $db->fetchAssociative('SELECT state_json, state_hash FROM presentation_state WHERE singleton = 1');
        if (false === $row) {
            return null;
        }
        try {
            if (hash('sha256', $row['state_json']) !== $row['state_hash']) {
                throw new \UnexpectedValueException('Presentation snapshot checksum mismatch.');
            }
            $state = json_decode($row['state_json'], true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($state) || ($state['sequence'] ?? null) !== $sequence || !\is_int($state['turn'] ?? null)
                || !\is_bool($state['navigation_pending'] ?? null)
                || !\array_key_exists('projection', $state) || (null !== $state['projection'] && !\is_array($state['projection']))) {
                throw new \UnexpectedValueException('Presentation snapshot watermark or shape mismatch.');
            }

            return $state;
        } catch (\JsonException|\UnexpectedValueException $exception) {
            $this->logger->warning('history_index.presentation_invalid_rebuild', $this->context($runId) + ['exception_class' => $exception::class]);

            return null;
        }
    }

    /** @return \Generator<int, \Ineersa\AgentCore\Domain\Event\RunEvent> */
    private function presentationEvents(JsonlRunEventLog $log, string $path, string $runId, int $endOffset): \Generator
    {
        $observation = new JsonlPhysicalReadObservation();
        try {
            foreach ($log->locatedLines($path, 0, $observation, $endOffset) as $location) {
                $record = $log->decodeLine($location['line']);
                $event = \is_array($record) ? $log->denormalizeRunEvent($record) : null;
                if (null !== $event) {
                    yield $event;
                }
                unset($location, $record, $event);
            }
        } finally {
            $this->observe($runId, 'index_presentation_rebuild', $observation);
        }
    }

    private function indexPath(string $path): string
    {
        return \dirname($path).'/history-index.sqlite';
    }

    private function markUnavailable(string $path, string $runId): void
    {
        try {
            (new Filesystem())->dumpFile($this->indexPath($path).'.unavailable', '1');
        } catch (\Throwable $exception) {
            $this->logger->warning('history_index.unavailable_marker_failed', $this->context($runId) + ['exception_class' => $exception::class]);
        }
    }

    private function connect(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->indexPath($path)]);
    }

    private function configureConnection(Connection $db): void
    {
        // Keep SQLite's page cache bounded too. No historical rows are fetched in bulk.
        $db->executeStatement('PRAGMA busy_timeout = 0');
        $db->executeStatement('PRAGMA cache_size = -1024');
        $db->executeStatement('PRAGMA temp_store = FILE');
        $db->executeStatement('PRAGMA journal_mode = WAL');
        $db->executeStatement('PRAGMA synchronous = NORMAL');
    }

    private function createSchema(Connection $db): void
    {
        // Bootstrap the disposable schema in one durable commit, not one WAL
        // synchronization per table/index before the first event is indexed.
        $db->transactional(static function (Connection $db): void {
            $db->executeStatement('CREATE TABLE IF NOT EXISTS index_meta (version INTEGER NOT NULL, run_id TEXT NOT NULL, device INTEGER NOT NULL, inode INTEGER NOT NULL, end_offset INTEGER NOT NULL, last_seq INTEGER NOT NULL, selected_anchor INTEGER NOT NULL, tip_anchor INTEGER NOT NULL, boundary_offset INTEGER NOT NULL, boundary_length INTEGER NOT NULL, boundary_hash TEXT NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS turn_anchor (anchor INTEGER PRIMARY KEY, turn_no INTEGER NOT NULL, predecessor INTEGER NOT NULL, retained INTEGER NOT NULL, presentation_member INTEGER NOT NULL DEFAULT 0, completion_seq INTEGER NOT NULL DEFAULT 0, prompt_seq INTEGER, prompt_preview TEXT, answer_seq INTEGER, answer_preview TEXT)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS pending_prompt (kind TEXT PRIMARY KEY, seq INTEGER NOT NULL, preview TEXT NOT NULL)');
            $db->executeStatement('CREATE INDEX IF NOT EXISTS turn_display ON turn_anchor (turn_no, anchor)');
            $db->executeStatement('CREATE INDEX IF NOT EXISTS turn_answers ON turn_anchor (answer_seq) WHERE retained = 1 AND answer_seq IS NOT NULL');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS event_location (seq INTEGER PRIMARY KEY, offset INTEGER NOT NULL, length INTEGER NOT NULL, type TEXT NOT NULL, turn_no INTEGER NOT NULL, anchor INTEGER NOT NULL)');
            $db->executeStatement('CREATE INDEX IF NOT EXISTS event_turn_anchor ON event_location (turn_no, anchor)');
            $db->executeStatement('CREATE INDEX IF NOT EXISTS event_anchor ON event_location (anchor, seq)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS command_record (seq INTEGER PRIMARY KEY, type TEXT NOT NULL, source_anchor INTEGER NOT NULL, target_anchor INTEGER, suppressed INTEGER NOT NULL DEFAULT 0)');
            $db->executeStatement('CREATE INDEX IF NOT EXISTS unresolved_commands ON command_record (source_anchor, seq) WHERE target_anchor IS NULL AND suppressed = 0');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS command_resolution (identity_hash TEXT PRIMARY KEY, sequence INTEGER NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS history_change (seq INTEGER PRIMARY KEY, kind TEXT NOT NULL, anchor INTEGER NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS context_checkpoint_location (seq INTEGER PRIMARY KEY, anchor INTEGER NOT NULL, offset INTEGER NOT NULL, length INTEGER NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS tool_invocation_location (tool_call_id TEXT PRIMARY KEY, seq INTEGER NOT NULL, turn_no INTEGER NOT NULL, step_id TEXT, attempt INTEGER NOT NULL, shell BOOLEAN NOT NULL, standalone BOOLEAN NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS retained_tool_invocation_location (tool_call_id TEXT PRIMARY KEY, seq INTEGER NOT NULL, turn_no INTEGER NOT NULL, step_id TEXT, attempt INTEGER NOT NULL, shell BOOLEAN NOT NULL, standalone BOOLEAN NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS agent_submission_location (identity_hash TEXT PRIMARY KEY, seq INTEGER NOT NULL)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS admission_step_location (singleton INTEGER PRIMARY KEY, turn_no INTEGER NOT NULL, step_id TEXT)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS admission_anchor (anchor INTEGER PRIMARY KEY)');
            $db->executeStatement('CREATE TABLE IF NOT EXISTS presentation_state (singleton INTEGER PRIMARY KEY, state_json TEXT NOT NULL, state_hash TEXT NOT NULL)');
        });
    }

    /** Opens, validates and catches up under the canonical storage lock. */
    private function synchronize(JsonlRunEventLog $log, string $path, string $runId, ?int $requestedEndSeq = null): Connection
    {
        $identity = $log->committedIdentity($path);
        $db = null;
        $meta = false;
        $validation = new JsonlPhysicalReadObservation();
        try {
            try {
                $missing = !is_file($this->indexPath($path));
                $db = $this->connect($path);
                $this->configureConnection($db);
                if ($missing) {
                    $this->createSchema($db);
                }
                $meta = $db->fetchAssociative('SELECT * FROM index_meta');
                // Check disposable schema presence on every new connection, including
                // replaced files. Missing objects require a rebuild, not repeated DDL.
                $schemaComplete = 19 === (int) $db->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE (type = 'table' AND name IN ('index_meta', 'turn_anchor', 'pending_prompt', 'event_location', 'command_record', 'command_resolution', 'history_change', 'context_checkpoint_location', 'tool_invocation_location', 'retained_tool_invocation_location', 'agent_submission_location', 'admission_step_location', 'admission_anchor', 'presentation_state')) OR (type = 'index' AND name IN ('turn_display', 'turn_answers', 'event_turn_anchor', 'event_anchor', 'unresolved_commands'))");
                $valid = $schemaComplete && !is_file($this->indexPath($path).'.unavailable') && false !== $meta
                    && self::VERSION === (int) $meta['version'] && $meta['run_id'] === $runId
                    && (int) $meta['device'] === $identity['device'] && (int) $meta['inode'] === $identity['inode']
                    && (int) $meta['end_offset'] >= 0 && (int) $meta['end_offset'] <= $identity['end_offset']
                    && (int) $meta['boundary_offset'] >= 0 && (int) $meta['boundary_length'] >= 0
                    && (int) $meta['boundary_offset'] + (int) $meta['boundary_length'] === (int) $meta['end_offset']
                    && (0 === (int) $meta['end_offset'] ? 0 === (int) $meta['last_seq'] : (int) $meta['last_seq'] > 0 && (int) $meta['boundary_length'] > 0);
                if ($valid && (int) $meta['boundary_length'] > 0) {
                    try {
                        foreach ($log->linesAt($path, [['offset' => (int) $meta['boundary_offset'], 'length' => (int) $meta['boundary_length']]], $validation) as $line) {
                            $boundary = $log->decodeLine($line);
                            $valid = hash('sha256', $line) === $meta['boundary_hash'] && \is_array($boundary)
                                && ($boundary['seq'] ?? null) === (int) $meta['last_seq'] && ($boundary['run_id'] ?? null) === $runId;
                        }
                    } catch (\RuntimeException|\JsonException $exception) {
                        $this->logger->warning('history_index.invalid_boundary_rebuild', $this->context($runId) + ['exception_class' => $exception::class]);
                        $valid = false;
                    }
                    unset($line, $boundary);
                }
            } catch (DbalException $exception) {
                if (!\in_array((int) $exception->getPrevious()?->getCode(), [1, 11, 26], true)) {
                    throw $exception;
                }
                $this->logger->warning('history_index.corrupt_rebuild', $this->context($runId) + ['exception_class' => $exception::class]);
                $db->close();
                (new Filesystem())->remove([$this->indexPath($path), $this->indexPath($path).'-wal', $this->indexPath($path).'-shm']);
                $db = $this->connect($path);
                $this->configureConnection($db);
                $this->createSchema($db);
                $meta = false;
                $schemaComplete = true;
                $valid = false;
            }
            if (!$valid && (false !== $meta || !$schemaComplete)) {
                $db->close();
                (new Filesystem())->remove([$this->indexPath($path), $this->indexPath($path).'-wal', $this->indexPath($path).'-shm']);
                $db = $this->connect($path);
                $this->configureConnection($db);
                $this->createSchema($db);
            }
            $this->observe($runId, 'index_boundary_validation', $validation);
            $start = $valid ? (int) $meta['end_offset'] : 0;
            $lastSeq = $valid ? (int) $meta['last_seq'] : 0;
            $selected = $valid ? (int) $meta['selected_anchor'] : 0;
            $tip = $valid ? (int) $meta['tip_anchor'] : 0;
            $presentation = $valid ? $this->loadPresentation($db, $runId, $lastSeq) : null;
            $projector = $log->presentationProjector();
            if (null !== $presentation && null !== $presentation['projection']) {
                try {
                    $projector->restore($presentation['projection']);
                } catch (\Throwable $exception) {
                    // This view is disposable independently of scalar locations.
                    $this->logger->warning('history_index.presentation_invalid_rebuild', $this->context($runId) + ['exception_class' => $exception::class]);
                    $presentation = null;
                }
            }
            $rebuildPresentation = null === $presentation;
            $presentationFailed = null !== $presentation && null === $presentation['projection'];
            $position = $presentation['turn'] ?? 0;
            $navigationPending = $presentation['navigation_pending'] ?? false;
            // A validated index may legitimately trail the archive. A range already
            // covered by that watermark needs no scan of unrelated newer records.
            if ($valid && !$rebuildPresentation && ($start === $identity['end_offset'] || (null !== $requestedEndSeq && $lastSeq >= $requestedEndSeq))) {
                return $db;
            }
            $observation = new JsonlPhysicalReadObservation();
            $db->beginTransaction();
            try {
                if (!$valid) {
                    foreach (['index_meta', 'event_location', 'turn_anchor', 'pending_prompt', 'command_record', 'command_resolution', 'history_change', 'context_checkpoint_location', 'tool_invocation_location', 'retained_tool_invocation_location', 'agent_submission_location', 'admission_step_location', 'admission_anchor', 'presentation_state'] as $table) {
                        $db->executeStatement('DELETE FROM '.$table);
                    }
                }
                $boundaryOffset = $valid ? (int) $meta['boundary_offset'] : 0;
                $boundaryLength = $valid ? (int) $meta['boundary_length'] : 0;
                $boundaryHash = $valid ? (string) $meta['boundary_hash'] : '';
                $end = $start;
                foreach ($log->locatedLines($path, $start, $observation) as $location) {
                    if (!str_ends_with($location['line'], "\n")) {
                        throw new \RuntimeException('Canonical index rebuild found an incomplete record.');
                    }
                    $record = $log->decodeLine($location['line']);
                    if (\is_array($record) && \is_int($record['seq'] ?? null) && $record['seq'] <= $lastSeq
                        && false !== $db->fetchOne('SELECT seq FROM event_location WHERE seq = ?', [$record['seq']])) {
                        throw new \Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException('Canonical history requires unique increasing sequences: duplicate sequence.');
                    }
                    if (!\is_array($record) || ($record['run_id'] ?? null) !== $runId || !\is_int($record['seq'] ?? null)
                        || $record['seq'] <= $lastSeq || !\is_string($record['type'] ?? null) || !\is_int($record['turn_no'] ?? null)
                        || !\is_array($record['payload'] ?? null)) {
                        throw new \RuntimeException('History indexing requires unique increasing canonical records from one run.');
                    }
                    // Preserve the stores' existing schema-read policy. Invisible
                    // versions still occupy physical locations, but cannot move history.
                    if ($log->isIncompatibleSchemaVersion($record)) {
                        $db->insert('event_location', ['seq' => $record['seq'], 'offset' => $location['offset'], 'length' => $location['length'], 'type' => $record['type'], 'turn_no' => $record['turn_no'], 'anchor' => 0]);
                    } else {
                        if ('turn_advanced' === $record['type']) {
                            $newTurn = (int) ($record['payload']['turn_no'] ?? $record['turn_no']);
                            // Reusing a displayed turn can re-include old canonical
                            // records even when no navigation occurs in this suffix.
                            $rebuildPresentation = $rebuildPresentation || ($newTurn > 0
                                && false === $db->fetchOne('SELECT anchor FROM turn_anchor WHERE turn_no = ? AND presentation_member = 1 LIMIT 1', [$newTurn])
                                && false !== $db->fetchOne('SELECT seq FROM event_location WHERE turn_no = ? LIMIT 1', [$newTurn]));
                        }
                        $this->indexRecord($db, $record, $location['offset'], $location['length'], $selected, $tip);
                        // Advances already move the presentation position. Their
                        // confirmation markers do not invalidate it, but a real
                        // selection can suppress seeds even at the same position.
                        $navigation = 'history_tail_discarded' === $record['type']
                            || ('history_position_set' === $record['type']
                                && ('history_select' === ($record['payload']['reason'] ?? null)
                                    || (int) ($record['payload']['position_turn_no'] ?? $record['turn_no']) !== $position));
                        if ($navigation) {
                            $navigationPending = true;
                            $rebuildPresentation = true;
                        } elseif ('turn_advanced' === $record['type'] && (int) ($record['payload']['turn_no'] ?? $record['turn_no']) > 0) {
                            // A previously suppressed seed can become visible when a
                            // later ordinary advance binds it, without another select.
                            $rebuildPresentation = $rebuildPresentation || $navigationPending;
                            $navigationPending = false;
                            $position = (int) ($record['payload']['turn_no'] ?? $record['turn_no']);
                        }
                        if (!$rebuildPresentation && !$presentationFailed) {
                            $included = 0 === $record['turn_no'] || false !== $db->fetchOne('SELECT t.anchor FROM turn_anchor t WHERE t.presentation_member = 1 AND t.turn_no = ? AND t.anchor <= (SELECT anchor FROM turn_anchor WHERE presentation_member = 1 AND turn_no = ? LIMIT 1) LIMIT 1', [$record['turn_no'], $position]);
                            try {
                                $event = $log->denormalizeRunEvent($record);
                                if (null !== $event) {
                                    $projector->observe($event, $included);
                                }
                                unset($event);
                            } catch (\Throwable $exception) {
                                // A malformed presentation payload must not poison
                                // unrelated location or admission lookups.
                                $this->logger->warning('history_index.presentation_failed', $this->context($runId) + ['exception_class' => $exception::class]);
                                $presentationFailed = true;
                            }
                        }
                    }
                    $lastSeq = $record['seq'];
                    $end = $location['offset'] + $location['length'];
                    $boundaryOffset = $location['offset'];
                    $boundaryLength = $location['length'];
                    $boundaryHash = hash('sha256', $location['line']);
                    unset($record, $location);
                }
                if ($end !== $identity['end_offset']) {
                    throw new \RuntimeException('Canonical index scan did not reach the committed boundary.');
                }
                if ($rebuildPresentation) {
                    try {
                        $projector = $log->presentationProjector();
                        $plan = HistoryReplayPlan::build($this->presentationEvents($log, $path, $runId, $end));
                        foreach ($this->presentationEvents($log, $path, $runId, $end) as $event) {
                            $projector->observe($event, $plan->includes($event));
                        }
                        unset($event);
                        $position = $plan->positionTurnNo;
                        $presentationFailed = false;
                    } catch (\Throwable $exception) {
                        $this->logger->warning('history_index.presentation_failed', $this->context($runId) + ['exception_class' => $exception::class]);
                        $presentationFailed = true;
                    }
                }
                $state = json_encode(['sequence' => $lastSeq, 'turn' => $position, 'navigation_pending' => $navigationPending, 'projection' => $presentationFailed ? null : $projector->snapshot()], \JSON_THROW_ON_ERROR);
                $db->executeStatement('INSERT INTO presentation_state (singleton, state_json, state_hash) VALUES (1, ?, ?) ON CONFLICT (singleton) DO UPDATE SET state_json = excluded.state_json, state_hash = excluded.state_hash', [$state, hash('sha256', $state)]);
                $db->executeStatement('DELETE FROM index_meta');
                $db->insert('index_meta', ['version' => self::VERSION, 'run_id' => $runId] + $identity + ['last_seq' => $lastSeq, 'selected_anchor' => $selected, 'tip_anchor' => $tip, 'boundary_offset' => $boundaryOffset, 'boundary_length' => $boundaryLength, 'boundary_hash' => $boundaryHash]);
                $db->commit();
                (new Filesystem())->remove($this->indexPath($path).'.unavailable');
            } catch (\Throwable $exception) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                throw $exception;
            } finally {
                $this->observe($runId, $valid ? 'index_suffix_catch_up' : 'index_cold_rebuild', $observation);
            }

            return $db;
        } catch (\Throwable $exception) {
            $db?->close();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $record */
    private function indexRecord(Connection $db, array $record, int $offset, int $length, int &$selected, int &$tip): void
    {
        $seq = $record['seq'];
        $type = $record['type'];
        $turn = $record['turn_no'];
        $payload = $record['payload'];
        if ('tool_execution_update' === $type && \is_string($payload['submission_receipt']['identity'] ?? null)) {
            $db->executeStatement('INSERT OR IGNORE INTO agent_submission_location (identity_hash, seq) VALUES (?, ?)', [hash('sha256', $payload['submission_receipt']['identity']), $seq]);
        }
        if (\in_array($type, ['agent_command_queued', 'agent_command_rejected'], true) && \is_string($payload['agent_communication']['identity'] ?? null)) {
            $db->executeStatement('INSERT OR IGNORE INTO agent_submission_location (identity_hash, seq) VALUES (?, ?)', [hash('sha256', $payload['agent_communication']['identity']), $seq]);
        }
        $promptText = HistoryPromptTextExtractor::extract($type, $payload);
        if ('' !== $promptText) {
            $kind = 'run_started' === $type ? 'initial' : 'human';
            $db->executeStatement('INSERT INTO pending_prompt (kind, seq, preview) VALUES (?, ?, ?) ON CONFLICT (kind) DO UPDATE SET seq = excluded.seq, preview = excluded.preview', [$kind, $seq, mb_strcut($promptText, 0, self::PREVIEW_BYTES, 'UTF-8')]);
        }
        unset($promptText);
        $anchor = $turn > 0 ? $this->anchorForTurn($db, $turn, retained: false) : 0;
        if ('turn_advanced' === $type) {
            $turn = (int) ($payload['turn_no'] ?? $turn);
            if ($turn > 0) {
                $anchor = $seq;
                // Presentation follows HistoryRetentionTracker's displayed-turn
                // retention, while scalar routing preserves immutable branch anchors.
                $member = false === $db->fetchOne('SELECT anchor FROM turn_anchor WHERE turn_no = ? AND presentation_member = 1 LIMIT 1', [$turn]);
                $db->insert('turn_anchor', ['anchor' => $anchor, 'turn_no' => $turn, 'predecessor' => $selected, 'retained' => 1, 'presentation_member' => (int) $member]);
                $prompt = $db->fetchAssociative("SELECT seq, preview FROM pending_prompt ORDER BY CASE kind WHEN 'human' THEN 0 ELSE 1 END LIMIT 1");
                if (false !== $prompt) {
                    $db->executeStatement('UPDATE turn_anchor SET prompt_seq = ?, prompt_preview = ? WHERE anchor = ?', [$prompt['seq'], $prompt['preview'], $anchor]);
                }
                $db->executeStatement('DELETE FROM pending_prompt');
                $selected = $tip = $anchor;
                $db->executeStatement('UPDATE event_location SET anchor = ? WHERE turn_no = ? AND anchor = 0', [$anchor, $turn]);
                $db->executeStatement('UPDATE command_record SET source_anchor = ? WHERE source_anchor = 0 AND seq IN (SELECT seq FROM event_location WHERE anchor = ?)', [$anchor, $anchor]);
                $db->executeStatement('UPDATE command_record SET target_anchor = ? WHERE target_anchor IS NULL AND suppressed = 0', [$anchor]);
            }
        } elseif ('history_position_set' === $type) {
            $position = (int) ($payload['position_turn_no'] ?? $turn);
            $candidate = $this->anchorForTurn($db, $position);
            if (0 === $position || $candidate > 0) {
                $selected = $candidate;
            }
            if ('history_select' === ($payload['reason'] ?? null) && $selected > 0) {
                $completion = (int) $db->fetchOne('SELECT completion_seq FROM turn_anchor WHERE anchor = ?', [$selected]);
                if ($completion > 0) {
                    $db->executeStatement('UPDATE command_record SET suppressed = 1 WHERE target_anchor IS NULL AND suppressed = 0 AND source_anchor = ? AND seq > ? AND seq < ?', [$selected, $completion, $seq]);
                }
            }
            $db->insert('history_change', ['seq' => $seq, 'kind' => $type, 'anchor' => $selected]);
        } elseif ('history_tail_discarded' === $type) {
            $db->executeStatement("DELETE FROM pending_prompt WHERE kind = 'human'");
            $after = (int) ($payload['after_turn_no'] ?? 0);
            $selected = $this->anchorForTurn($db, $after);
            // Linear retained order is anchor creation order, never displayed numbers.
            $db->executeStatement('UPDATE turn_anchor SET retained = 0 WHERE retained = 1 AND anchor > ?', [$selected]);
            $db->executeStatement('UPDATE turn_anchor SET presentation_member = 0 WHERE turn_no > ?', [$after]);
            $tip = $selected;
            $db->insert('history_change', ['seq' => $seq, 'kind' => $type, 'anchor' => $selected]);
        } elseif (\in_array($type, ['agent_end', 'llm_step_completed'], true) && $anchor > 0) {
            $db->executeStatement('UPDATE turn_anchor SET completion_seq = ? WHERE anchor = ?', [$seq, $anchor]);
            if ('llm_step_completed' === $type) {
                $answerText = HistoryAssistantTextExtractor::extract($payload);
                $db->executeStatement('UPDATE turn_anchor SET answer_seq = ?, answer_preview = ? WHERE anchor = ?', ['' !== $answerText ? $seq : null, '' !== $answerText ? mb_strcut($answerText, 0, self::PREVIEW_BYTES, 'UTF-8') : null, $anchor]);
            }
        }
        $db->insert('event_location', ['seq' => $seq, 'offset' => $offset, 'length' => $length, 'type' => $type, 'turn_no' => $record['turn_no'], 'anchor' => $anchor]);
        if (\in_array($type, ['agent_command_queued', 'agent_command_applied'], true)) {
            $db->insert('command_record', ['seq' => $seq, 'type' => $type, 'source_anchor' => $anchor]);
        }
        $key = $payload['idempotency_key'] ?? null;
        if (\is_string($key) && '' !== $key && \in_array($type, ['agent_command_queued', 'agent_command_applied', 'agent_command_rejected'], true)) {
            // IDs may be reused after completion. Keep only their latest canonical
            // location on disk, not old text or a new command-deduplication registry.
            $db->executeStatement('INSERT INTO command_resolution (identity_hash, sequence) VALUES (?, ?) ON CONFLICT (identity_hash) DO UPDATE SET sequence = excluded.sequence', [hash('sha256', $key), $seq]);
        }
        if ('context_compacted' === $type) {
            $db->insert('context_checkpoint_location', ['seq' => $seq, 'anchor' => $anchor, 'offset' => $offset, 'length' => $length]);
        }
        $this->indexInvocationLocations($db, $record, $selected);
    }

    /** @param array<string, mixed> $record */
    private function indexInvocationLocations(Connection $db, array $record, int $selected): void
    {
        $type = $record['type'];
        $payload = $record['payload'];
        if ('turn_advanced' === $type && $selected > 0) {
            $db->executeStatement('INSERT OR IGNORE INTO admission_anchor (anchor) VALUES (?)', [$selected]);
        } elseif (\in_array($type, ['history_position_set', 'history_tail_discarded'], true) && (int) $db->fetchOne('SELECT MAX(anchor) FROM admission_anchor') !== $selected) {
            // History navigation is exceptional; warm point lookups never walk
            // predecessors. Ordinary new turns extend this cached chain once.
            $db->executeStatement('DELETE FROM admission_anchor');
            $db->executeStatement('WITH RECURSIVE prefix(anchor) AS (SELECT ? WHERE ? > 0 UNION ALL SELECT t.predecessor FROM turn_anchor t JOIN prefix p ON t.anchor = p.anchor WHERE t.predecessor > 0) INSERT INTO admission_anchor SELECT anchor FROM prefix', [$selected, $selected]);
        }
        $selection = 'history_position_set' === $type && 'history_select' === ($payload['reason'] ?? null);
        if ('run_started' === $type || $selection) {
            $db->executeStatement('DELETE FROM tool_invocation_location');
        } elseif (\in_array($type, ['llm_step_completed', 'llm_step_failed', 'tool_batch_committed', 'agent_end'], true)) {
            $db->executeStatement('DELETE FROM tool_invocation_location WHERE shell = 0');
        }
        if ($selection || \in_array($type, ['agent_end', 'context_compacted'], true)) {
            $db->executeStatement('DELETE FROM admission_step_location');
        } elseif ('context_compaction_failed' === $type) {
            $step = $db->fetchOne('SELECT step_id FROM admission_step_location');
            if (ReplayLifecycleStatus::compactionFailure(RunStatus::Compacting, false === $step ? null : $step, $payload)['terminal']) {
                $db->executeStatement('DELETE FROM admission_step_location');
            }
        } elseif (\in_array($type, ['run_started', 'turn_advanced', 'context_compaction_started'], true)) {
            $previousStep = 'run_started' === $type ? null : $db->fetchOne('SELECT step_id FROM admission_step_location');
            $step = \is_string($payload['step_id'] ?? null) ? $payload['step_id'] : (false === $previousStep ? null : $previousStep);
            $turn = 'turn_advanced' === $type ? ($payload['turn_no'] ?? $record['turn_no']) : $record['turn_no'];
            $db->executeStatement('INSERT INTO admission_step_location (singleton, turn_no, step_id) VALUES (1, ?, ?) ON CONFLICT (singleton) DO UPDATE SET turn_no = excluded.turn_no, step_id = excluded.step_id', [$turn, $step]);
        }
        if ('agent_command_applied' === $type && 'shell_command' === ($payload['kind'] ?? null) && \is_string($payload['idempotency_key'] ?? null)) {
            $operation = $payload['current_operation'] ?? [];
            $valid = \is_array($operation) && ($operation['idempotency_key'] ?? null) === $payload['idempotency_key'] && \is_bool($payload['standalone'] ?? null);
            // Cache malformed active identity as such; an unrelated historical
            // command must not break all lookups after it has been retired.
            $this->storeInvocationLocation($db, 'sh_'.hash('sha256', $payload['idempotency_key']), $record['seq'], $valid ? ($operation['turn_no'] ?? $record['turn_no']) : $record['turn_no'], $valid ? ($operation['step_id'] ?? null) : null, $valid ? ($operation['attempt'] ?? 0) : 0, true, true === ($payload['standalone'] ?? null));
        } elseif ('llm_step_completed' === $type) {
            $step = $db->fetchOne('SELECT step_id FROM admission_step_location');
            foreach ($payload['assistant_message']['tool_calls'] ?? [] as $call) {
                if (\is_string($call['id'] ?? null)) {
                    $this->storeInvocationLocation($db, $call['id'], $record['seq'], $record['turn_no'], false === $step ? null : $step, 1, false, false);
                    // Detached activity still needs its immutable origin after the
                    // batch settles. Ancestry checks, not current-step membership,
                    // determine whether that origin survives history navigation.
                    $db->executeStatement('INSERT OR IGNORE INTO retained_tool_invocation_location SELECT * FROM tool_invocation_location WHERE tool_call_id = ?', [$call['id']]);
                }
            }
        } elseif ('tool_execution_start' === $type && \is_string($payload['tool_call_id'] ?? null)) {
            // Keep the original canonical source when observing execution starts.
            // Approval retries can advance the batch attempt without another start;
            // the batch reference, not this index, owns the current attempt.
            $step = $db->fetchOne('SELECT step_id FROM admission_step_location');
            $db->executeStatement('INSERT OR IGNORE INTO tool_invocation_location (tool_call_id, seq, turn_no, step_id, attempt, shell, standalone) VALUES (?, ?, ?, ?, ?, 0, 0)', [$payload['tool_call_id'], $record['seq'], $record['turn_no'], false === $step ? null : $step, $payload['attempt'] ?? 1]);
            $db->executeStatement('UPDATE tool_invocation_location SET attempt = COALESCE(?, attempt) WHERE tool_call_id = ?', [\is_int($payload['attempt'] ?? null) ? $payload['attempt'] : null, $payload['tool_call_id']]);
        } elseif (\in_array($type, ['tool_execution_end', 'tool_call_acknowledged'], true) && \is_string($payload['tool_result']['tool_call_id'] ?? null)) {
            // Ordinary completed calls remain members until batch settlement.
            $db->executeStatement('DELETE FROM tool_invocation_location WHERE tool_call_id = ? AND shell = 1', [$payload['tool_result']['tool_call_id']]);
        }
    }

    private function storeInvocationLocation(Connection $db, string $id, int $seq, int $turn, ?string $step, int $attempt, bool $shell, bool $standalone): void
    {
        $db->executeStatement('INSERT INTO tool_invocation_location (tool_call_id, seq, turn_no, step_id, attempt, shell, standalone) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (tool_call_id) DO UPDATE SET seq = excluded.seq, turn_no = excluded.turn_no, step_id = excluded.step_id, attempt = excluded.attempt, shell = excluded.shell, standalone = excluded.standalone', [$id, $seq, $turn, $step, $attempt, (int) $shell, (int) $standalone]);
    }

    private function anchorForTurn(Connection $db, int $turn, bool $retained = true): int
    {
        if (0 === $turn) {
            return 0;
        }
        $anchor = $db->fetchOne('SELECT anchor FROM turn_anchor WHERE turn_no = ?'.($retained ? ' AND retained = 1' : '').' ORDER BY anchor DESC LIMIT 1', [$turn]);

        return false === $anchor ? 0 : (int) $anchor;
    }

    /** @return array{run_id: string, session_id: string, component: string, event_type: string} */
    private function context(string $runId): array
    {
        return ['run_id' => $runId, 'session_id' => $runId, 'component' => 'history_index', 'event_type' => 'history_index'];
    }

    private function observe(string $runId, string $reason, JsonlPhysicalReadObservation $observation): void
    {
        $this->logger->debug('history_index.physical_read', $this->context($runId) + ['read_reason' => $reason, 'archive_bytes_read' => $observation->archiveBytesRead(), 'lines_yielded' => $observation->linesYielded(), 'full_scan' => !\in_array($reason, ['index_boundary_validation', 'index_suffix_catch_up'], true) && $observation->fullScan()]);
    }
}
