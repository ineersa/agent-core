<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Store\Bridge\Sqlite\Store as TextStore;
use Symfony\AI\Store\Bridge\Vektor\Store as VectorStore;
use Symfony\AI\Store\CombinedStore;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Canonical memory stays in SQLite. Binary vectors and FTS are disposable.
 * A committed dirty marker precedes every cross-store write. A worker lost
 * mid-write cannot publish partial storage; its successor rebuilds from source.
 *
 * @phpstan-type Memory array{kind: string, session_id: string, id: string, timestamp: string, content: string, importance?: string}
 * @phpstan-type Chunk array{parent: string, text: string}
 */
final readonly class SemanticIndexService
{
    private const int FUSED_CANDIDATE_LIMIT = 200;

    private string $directory;

    public function __construct(
        private Connection $connection,
        string $databasePath,
        private SemanticSettings $settings,
        private SemanticApiClient $client,
        private LoggerInterface $logger = new NullLogger(),
        private ?string $runId = null,
    ) {
        $this->directory = $databasePath.'.semantic';
    }

    /** Index one embedding batch. False means another job must continue. */
    public function synchronize(): bool
    {
        $lock = $this->lock();
        try {
            $state = $this->state();
            $text = TextStore::fromDbal($this->connection, 'om_semantic_document');
            $textExists = 2 === (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('om_semantic_document', 'om_semantic_document_fts')");
            $text->setup();
            $count = $this->chunkCount();
            $reset = null === $state || 1 === (int) $state['dirty'] || $state['signature'] !== $this->settings->signature()
                || !$textExists || $count !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document_fts')
                || (!$this->filesExist() && 0 !== $count);
            if ($reset) {
                $this->rebuild($text, null === $state);
                $state = null;
            }
            $snapshot = $this->refreshSources();
            $sourceHash = $snapshot['hash'];
            if ($snapshot['obsolete'] && null !== $state && 1 === (int) $state['complete']
                && $state['source_hash'] === $sourceHash
                && false === $this->connection->fetchOne($this->pendingSql().' LIMIT 1')
                && false === $this->connection->fetchOne("WITH valid AS MATERIALIZED (SELECT c.value AS id FROM om_semantic_document s, json_each(s.metadata, '$.chunks') c WHERE s.id LIKE 'source:%') SELECT d.id FROM om_semantic_document d LEFT JOIN valid v ON v.id = d.id WHERE d.id NOT LIKE 'source:%' AND v.id IS NULL LIMIT 1")) {
                // Only a healthy, clean, complete same-settings index reaches
                // here. Restored source and exact chunk membership permit reuse;
                // an earlier search may have marked the intermediate content obsolete.
                $this->connection->update('om_semantic_progress', ['obsolete' => 0], ['id' => 1]);
                $snapshot['obsolete'] = false;
            }
            // Removed or changed chunk IDs require a physical rebuild. Vektor
            // tombstones can exhaust its fixed candidate buffer.
            if ($snapshot['obsolete']) {
                $this->rebuild($text, false);
                $state = null;
                $sourceHash = $this->refreshSources()['hash'];
            }
            $dimensions = (int) ($state['dimensions'] ?? 0);
            $batch = [];
            $limit = min(4, $this->settings->embeddingBatchSize);
            $parents = $this->connection->fetchFirstColumn($this->pendingSql().' ORDER BY s.id LIMIT '.$limit);
            foreach ($parents as $parent) {
                $memory = json_decode((string) $this->connection->fetchOne("SELECT json_extract(metadata, '$.source') FROM om_semantic_document WHERE id = ?", [$parent]), true, flags: \JSON_THROW_ON_ERROR);
                $key = $memory['kind'].':'.$memory['id'];
                $indexed = array_fill_keys($this->connection->fetchFirstColumn("SELECT d.id FROM om_semantic_document s JOIN json_each(s.metadata, '$.chunks') c JOIN om_semantic_document d ON d.id = c.value WHERE s.id = ?", [$parent]), true);
                $pending = array_diff_key($this->chunks([$key => $memory]), $indexed);
                $batch += \array_slice($pending, 0, $limit - \count($batch), true);
                if (\count($batch) === $limit) {
                    break;
                }
            }
            if ([] !== $batch) {
                $vectors = $this->client->embed(array_values(array_column($batch, 'text')));
                $batchDimensions = $vectors[0]->getDimensions();
                if (0 !== $dimensions && $dimensions !== $batchDimensions) {
                    $this->saveState($sourceHash, $dimensions, true, false);
                    throw new \RuntimeException('Embedding dimensions changed; semantic index requires rebuilding.');
                }
                $dimensions = $batchDimensions;
                $documents = [];
                foreach (array_keys($batch) as $position => $id) {
                    $documents[] = new VectorDocument($id, $vectors[$position], new Metadata([
                        Metadata::KEY_PARENT_ID => $batch[$id]['parent'],
                        Metadata::KEY_TEXT => $batch[$id]['text'],
                    ]));
                }
                $this->saveState($sourceHash, $dimensions, true, false);
                $this->vectorStore($dimensions)->add($documents);
                (new MemoryStoreAdapter($text, true, 0))->add($documents);
            }
            // Observer commits can occur while this worker embeds. Compare the
            // exact source snapshot, not a database-wide change counter.
            $currentHash = $this->refreshSources()['hash'];
            $complete = $sourceHash === $currentHash
                && false === $this->connection->fetchOne($this->pendingSql().' LIMIT 1');
            $this->saveState($sourceHash, $dimensions, false, $complete);

            return $complete;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{observation: array{?string, ?string}, reflection: array{?string, ?string}} $dates
     * @param \Closure(): void                                                                 $checkpoint
     *
     * @return array{results: list<Memory>, truncated: bool, partial: bool}
     */
    public function search(string $query, array $dates, \Closure $checkpoint): array
    {
        $lock = $this->lock();
        try {
            $state = $this->state();
            if (null === $state || 1 === (int) $state['dirty'] || $state['signature'] !== $this->settings->signature()) {
                throw $this->building($state);
            }
            if (false === $this->connection->fetchOne('SELECT id FROM ('.$this->sourceSql().') LIMIT 1')) {
                return ['results' => [], 'truncated' => false, 'partial' => false];
            }
            if (0 === (int) $state['dimensions']) {
                throw $this->building($state);
            }
            $snapshotHash = $this->refreshSources()['hash'];
            $partial = 0 === (int) $state['complete'] || $state['source_hash'] !== $snapshotHash;
            // Network calls must not hold the writer lock. Revalidate the exact
            // source/index snapshot after embedding before reading either store.
            $lock->release();
            $checkpoint();
            $vector = $this->client->embed([$query], query: true)[0];
            $checkpoint();
            $lock = $this->lock();
            $currentState = $this->state();
            $currentSnapshot = $this->refreshSources();
            if ($state !== $currentState || $snapshotHash !== $currentSnapshot['hash']) {
                throw $this->building($currentState);
            }
            if ($vector->getDimensions() !== (int) $state['dimensions']) {
                $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                throw new SemanticSearchException('embedding_dimensions_changed', 'Query embedding dimensions changed; the semantic index must rebuild.');
            }
            [$dateSql, $dateParameters] = $this->datePredicate($dates);
            $hasDateBounds = [] !== array_filter([...$dates['observation'], ...$dates['reflection']], static fn (?string $bound): bool => null !== $bound);
            $hasDates = $hasDateBounds && false !== $this->connection->fetchOne("SELECT s.id FROM om_semantic_document s WHERE s.id LIKE 'source:%' AND NOT (".$dateSql.') LIMIT 1', $dateParameters);
            $filter = ($hasDates || $partial) ? function (array $page) use ($dateSql, $dateParameters, $checkpoint): array {
                $checkpoint();
                $parents = [];
                $ids = [];
                foreach ($page as $document) {
                    $parent = 'source:'.$document->getMetadata()->getParentId();
                    $parents[$parent] = true;
                    $ids[$document->getId()] = $parent;
                }
                $rows = $this->connection->fetchAllAssociative("SELECT s.id AS parent, c.value AS id FROM om_semantic_document s JOIN json_each(s.metadata, '$.chunks') c WHERE s.id IN (?) AND c.value IN (?) AND (".$dateSql.')',
                    [array_keys($parents), array_keys($ids), ...$dateParameters],
                    [ArrayParameterType::STRING, ArrayParameterType::STRING]);
                $valid = [];
                foreach ($rows as $row) {
                    if (($ids[$row['id']] ?? null) === $row['parent']) {
                        $valid[$row['id']] = true;
                    }
                }
                $checkpoint();

                return $valid;
            } : null;
            try {
                if (!$this->filesExist()) {
                    $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                    throw new \RuntimeException('Semantic vector files are missing.');
                }
                $count = $this->chunkCount();
                $vectorStore = new MemoryStoreAdapter($this->vectorStore((int) $state['dimensions']), false, $count, $filter);
                $textStore = new MemoryStoreAdapter(TextStore::fromDbal($this->connection, 'om_semantic_document'), true, $count, $filter);
                $store = new CombinedStore($vectorStore, $textStore);
                $hits = \array_slice(iterator_to_array($store->query(new HybridQuery($vector, $query), ['maxItems' => 100]), false), 0, self::FUSED_CANDIDATE_LIMIT);
            } catch (\Throwable $error) {
                // SQLite explicitly identifies corruption. Busy/locked databases
                // and other transient failures must not discard valid embeddings.
                if (($error instanceof \PDOException && \in_array((int) ($error->errorInfo[1] ?? 0) & 0xFF, [11, 26], true))
                    || ($error instanceof \RuntimeException && 1 === preg_match('/^Node [0-9]+ not found in graph$/D', $error->getMessage()))) {
                    $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                }
                throw $error;
            }
            $parents = [];
            foreach ($hits as $hit) {
                $parent = (string) $hit->getMetadata()->getParentId();
                $parents['source:'.$parent] = true;
            }
            // These manifests were validated against canonical source in one
            // SQLite snapshot after embedding. Only hit content enters PHP,
            // and it is frozen before releasing the index lock.
            $canonical = [];
            foreach ($this->connection->fetchFirstColumn("SELECT json_extract(metadata, '$.source') FROM om_semantic_document WHERE id IN (?)", [array_keys($parents)], [ArrayParameterType::STRING]) as $source) {
                $memory = json_decode((string) $source, true, flags: \JSON_THROW_ON_ERROR);
                $canonical[$memory['kind'].':'.$memory['id']] = $memory;
            }
            foreach ($hits as $hit) {
                if (!isset($canonical[(string) $hit->getMetadata()->getParentId()])) {
                    throw new \RuntimeException('Semantic result has no canonical memory.');
                }
            }
            // Hits and canonical source rows are materialized. Their immutable
            // snapshot can be reranked without blocking asynchronous indexing.
            $lock->release();
            $checkpoint();
            $candidateLimitReached = \count($hits) >= self::FUSED_CANDIDATE_LIMIT;
            if (null !== $this->settings->rerankerUrl && [] !== $hits) {
                $order = $this->client->rerank($query, array_map(static fn (VectorDocumentInterface $hit): string => $hit->getMetadata()->getText() ?? '', $hits), $checkpoint);
                $hits = array_map(static fn (int $index): VectorDocumentInterface => $hits[$index], $order);
            }
            $results = [];
            foreach ($hits as $hit) {
                $parent = (string) $hit->getMetadata()->getParentId();
                $results[$parent] ??= $canonical[$parent];
            }
            $checkpoint();

            return ['results' => array_values($results), 'truncated' => $candidateLimitReached || $vectorStore->wasTruncated() || $textStore->wasTruncated(), 'partial' => $partial];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, array{?string, ?string}> $dates
     * @return array{string, list<string>}
     */
    private function datePredicate(array $dates): array
    {
        $kinds = [];
        $parameters = [];
        foreach ($dates as $kind => [$after, $before]) {
            $conditions = ["json_extract(s.metadata, '$.source.kind') = ?"];
            $parameters[] = $kind;
            if (null !== $after) {
                $conditions[] = "json_extract(s.metadata, '$.source.timestamp') >= ?";
                $parameters[] = $after;
            }
            if (null !== $before) {
                $conditions[] = "json_extract(s.metadata, '$.source.timestamp') <= ?";
                $parameters[] = $before;
            }
            $kinds[] = '('.implode(' AND ', $conditions).')';
        }

        return [implode(' OR ', $kinds), $parameters];
    }

    private function rebuild(TextStore $text, bool $initial): void
    {
        $this->saveState('', 0, true, false);
        (new Filesystem())->remove($this->directory.'/vektor');
        $text->clear();
        $this->connection->update('om_semantic_progress', ['revision' => 0, 'source_hash' => str_repeat('0', 64), 'initialized' => 0, 'obsolete' => 0], ['id' => 1]);
        $this->logger->log($initial ? 'info' : 'warning', 'om.semantic.rebuild', ['run_id' => $this->runId, 'session_id' => $this->runId, 'component' => 'observational_memory', 'event_type' => 'om.semantic.rebuild']);
    }

    private function chunkCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM om_semantic_document WHERE id NOT LIKE 'source:%'");
    }

    private function pendingSql(): string
    {
        return "SELECT s.id FROM om_semantic_document s WHERE s.id LIKE 'source:%' AND EXISTS (SELECT 1 FROM json_each(s.metadata, '$.chunks') c LEFT JOIN om_semantic_document d ON d.id = c.value WHERE d.id IS NULL)";
    }

    /** @param array<string, mixed>|null $state */
    private function building(?array $state): SemanticSearchException
    {
        $indexedCount = 0;
        if (null !== $state && 0 === (int) $state['dirty'] && $state['signature'] === $this->settings->signature()
            && false !== $this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'om_semantic_document'")) {
            $indexedCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM (SELECT DISTINCT json_extract(metadata, '$._parent_id') AS parent FROM om_semantic_document WHERE id NOT LIKE 'source:%') d JOIN (".$this->sourceSql().") s ON d.parent = s.kind || ':' || s.id");
        }

        $sourceCount = (int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM om_observation) + (SELECT COUNT(*) FROM om_reflection)');

        return new SemanticSearchException('index_building', 'Semantic index is building or stale; counts show source memories represented by at least one indexed chunk, not search readiness.', ['indexed_count' => $indexedCount, 'source_count' => $sourceCount]);
    }

    private function lock(): LockInterface
    {
        (new Filesystem())->mkdir($this->directory, 0o700);
        $lock = (new LockFactory(new FlockStore($this->directory)))->createLock('semantic-index');
        if (!$lock->acquire()) {
            throw new SemanticSearchException('index_busy', 'Semantic index is busy; retry after indexing completes.');
        }

        return $lock;
    }

    private function vectorStore(int $dimensions): VectorStore
    {
        // setup() sets Vektor's process-global Config. Never cache store instances
        // across operations: an extension worker can next serve a different CWD.
        $store = new VectorStore($this->directory, $dimensions);
        $store->setup();

        return $store;
    }

    private function filesExist(): bool
    {
        foreach (['vector.bin', 'graph.bin', 'meta.bin', 'payload.bin'] as $file) {
            if (!is_file($this->directory.'/vektor/'.$file)) {
                return false;
            }
        }

        return true;
    }

    private function sourceSql(): string
    {
        return "SELECT 'observation' AS kind, observation_id AS id, run_id AS session_id, content, timestamp, relevance AS importance FROM om_observation UNION ALL SELECT 'reflection' AS kind, reflection_id AS id, run_id AS session_id, content, created_at AS timestamp, NULL AS importance FROM om_reflection";
    }

    /**
     * Reconcile changed identities in the same snapshot as the revision cursor.
     * Triggers cover canonical SQL mutations, including deletes and mutable content.
     * A reversible set digest preserves snapshots when content changes and returns
     * to its original value during a network wait. Unrelated writes do not enter it.
     *
     * @return array{hash: string, obsolete: bool}
     */
    private function refreshSources(): array
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->connection->transactional(function (): array {
                    $progress = $this->connection->fetchAssociative('SELECT * FROM om_semantic_progress WHERE id = 1');
                    $revision = (int) $this->connection->fetchOne('SELECT revision FROM om_source_revision WHERE id = 1');
                    $full = 0 === (int) $progress['initialized'];
                    $hash = hex2bin((string) $progress['source_hash']);
                    $obsolete = 1 === (int) $progress['obsolete'];
                    if (!$full && $revision === (int) $progress['revision']) {
                        return ['hash' => bin2hex($hash), 'obsolete' => $obsolete];
                    }
                    $removed = [];
                    foreach ($this->sourceChanges($full, (int) $progress['revision'], $revision) as $row) {
                        $parent = $row['kind'].':'.$row['id'];
                        $old = null === $row['old_metadata'] ? null : json_decode((string) $row['old_metadata'], true, flags: \JSON_THROW_ON_ERROR);
                        $metadata = null;
                        if (null !== $row['canonical_id']) {
                            $memory = array_intersect_key($row, array_flip(['kind', 'id', 'session_id', 'content', 'timestamp', 'importance']));
                            if (null === $memory['importance']) {
                                unset($memory['importance']);
                            }
                            $metadata = ['source' => $memory, 'signature' => hash('sha256', json_encode($memory, \JSON_THROW_ON_ERROR)), 'chunks' => array_keys($this->chunks([$parent => $memory]))];
                        }
                        if ($old === $metadata) {
                            continue;
                        }
                        if (null !== $old) {
                            $hash ^= hex2bin($old['signature']);
                            if (!$obsolete) {
                                foreach (array_diff($old['chunks'], $metadata['chunks'] ?? []) as $id) {
                                    $removed[] = $id;
                                    if (400 === \count($removed)) {
                                        $obsolete = $this->indexedChunkExists($removed);
                                        $removed = [];
                                        if ($obsolete) {
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if (null === $metadata) {
                            if (null !== $old) {
                                $this->connection->delete('om_semantic_document', ['id' => 'source:'.$parent]);
                            }
                        } else {
                            $hash ^= hex2bin($metadata['signature']);
                            // DBAL keeps manifest publication and cursor atomic. Vendor
                            // TextStore::add owns a native transaction and cannot nest.
                            $this->connection->executeStatement('INSERT INTO om_semantic_document (id, vector, metadata) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET metadata = excluded.metadata', ['source:'.$parent, '[1.0]', json_encode($metadata, \JSON_THROW_ON_ERROR)]);
                        }
                    }
                    $obsolete = $obsolete || ([] !== $removed && $this->indexedChunkExists($removed));
                    $hash = bin2hex($hash);
                    $this->connection->update('om_semantic_progress', ['revision' => $revision, 'source_hash' => $hash, 'initialized' => 1, 'obsolete' => (int) $obsolete], ['id' => 1]);
                    $this->connection->executeStatement('DELETE FROM om_source_change WHERE revision <= ?', [$revision]);

                    return ['hash' => $hash, 'obsolete' => $obsolete];
                });
            } catch (LockWaitTimeoutException $error) {
                if (517 !== $error->getCode()) {
                    throw $error;
                }
                // A committed writer invalidated this deferred read snapshot.
                // Waiting cannot repair it: DBAL rolled back the whole attempt,
                // so retry from a fresh snapshot, never just the failed upsert.
                $retry = $attempt < 3;
                $this->logger->warning('om.semantic.source_refresh_conflict', ['run_id' => $this->runId, 'session_id' => $this->runId, 'component' => 'observational_memory', 'event_type' => 'om.semantic.source_refresh_conflict', 'sqlite_code' => 517, 'attempt' => $attempt, 'retrying' => $retry]);
                if (!$retry) {
                    throw $error;
                }
            }
        }
    }

    /**
     * Full reconstruction follows rebuild(), which cleared all manifests.
     * Incremental pages join old manifests and both canonical tables, retaining
     * tombstones and old identities after primary-key changes through LEFT JOINs.
     *
     * @return iterable<array<string, mixed>>
     */
    private function sourceChanges(bool $full, int $fromRevision, int $revision): iterable
    {
        if ($full) {
            yield from $this->connection->iterateAssociative('SELECT s.*, s.id AS canonical_id, NULL AS old_metadata FROM ('.$this->sourceSql().') s ORDER BY s.kind, s.id');

            return;
        }
        $cursor = [$fromRevision, '', ''];
        do {
            $page = $this->connection->fetchAllAssociative(
                "SELECT c.kind, c.source_id AS id,
                    CASE c.kind WHEN 'observation' THEN o.run_id ELSE r.run_id END AS session_id,
                    CASE c.kind WHEN 'observation' THEN o.content ELSE r.content END AS content,
                    CASE c.kind WHEN 'observation' THEN o.timestamp ELSE r.created_at END AS timestamp,
                    CASE c.kind WHEN 'observation' THEN o.relevance ELSE NULL END AS importance,
                    COALESCE(o.observation_id, r.reflection_id) AS canonical_id,
                    d.metadata AS old_metadata, c.revision AS change_revision
                 FROM om_source_change c
                 LEFT JOIN om_semantic_document d ON d.id = 'source:' || c.kind || ':' || c.source_id
                 LEFT JOIN om_observation o ON c.kind = 'observation' AND o.observation_id = c.source_id
                 LEFT JOIN om_reflection r ON c.kind = 'reflection' AND r.reflection_id = c.source_id
                 WHERE c.revision > ? AND c.revision <= ?
                    AND (c.revision, c.kind, c.source_id) > (?, ?, ?)
                 ORDER BY c.revision, c.kind, c.source_id LIMIT 200",
                [$fromRevision, $revision, ...$cursor],
            );
            foreach ($page as $row) {
                $cursor = [(int) $row['change_revision'], $row['kind'], $row['id']];
                yield $row;
            }
        } while (200 === \count($page));
    }

    /** @param list<string> $ids */
    private function indexedChunkExists(array $ids): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM om_semantic_document WHERE id IN (?) LIMIT 1', [$ids], [ArrayParameterType::STRING]);
    }

    /** @param array<string, Memory> $memories
     * @return array<string, Chunk>
     */
    private function chunks(array $memories): array
    {
        $chunker = new MemoryChunker($this->settings);
        $chunks = [];
        foreach ($memories as $parent => $memory) {
            foreach ($chunker->split($memory['content']) as $position => $text) {
                $id = self::chunkId($parent, $position, $text);
                $chunks[$id] = ['parent' => $parent, 'text' => $text];
            }
        }

        return $chunks;
    }

    private static function chunkId(string $parent, int $position, string $text): string
    {
        // 32 hexadecimal characters fit Vektor's 36-byte external-ID field.
        return substr(hash('sha256', $parent.'\0'.$position.'\0'.$text), 0, 32);
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $state = $this->connection->fetchAssociative('SELECT * FROM om_semantic_state WHERE id = 1');

        return false === $state ? null : $state;
    }

    private function saveState(string $hash, int $dimensions, bool $dirty, bool $complete): void
    {
        $current = $this->state();
        if (null !== $current && $current['signature'] === $this->settings->signature()
            && $current['source_hash'] === $hash && (int) $current['dimensions'] === $dimensions
            && (int) $current['dirty'] === (int) $dirty && (int) $current['complete'] === (int) $complete) {
            return;
        }
        $this->connection->executeStatement('INSERT INTO om_semantic_state (id, signature, source_hash, dimensions, dirty, complete) VALUES (1, ?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET signature = excluded.signature, source_hash = excluded.source_hash, dimensions = excluded.dimensions, dirty = excluded.dirty, complete = excluded.complete', [$this->settings->signature(), $hash, $dimensions, (int) $dirty, (int) $complete]);
    }
}
