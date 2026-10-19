<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
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
            $memories = $this->refreshSources();
            $sourceHash = $this->sourceHash($memories);
            // Removed or changed chunk IDs require a physical rebuild. Vektor
            // tombstones can exhaust its fixed candidate buffer.
            if (false !== $this->connection->fetchOne("WITH valid AS MATERIALIZED (SELECT c.value AS id FROM om_semantic_document s, json_each(s.metadata, '$.chunks') c WHERE s.id LIKE 'source:%') SELECT d.id FROM om_semantic_document d LEFT JOIN valid v ON v.id = d.id WHERE d.id NOT LIKE 'source:%' AND v.id IS NULL LIMIT 1")) {
                $this->rebuild($text, false);
                $state = null;
                $memories = $this->refreshSources();
                $sourceHash = $this->sourceHash($memories);
            }
            $dimensions = (int) ($state['dimensions'] ?? 0);
            $this->saveState($sourceHash, $dimensions, false, false);
            $batch = [];
            $limit = min(4, $this->settings->embeddingBatchSize);
            $parents = $this->connection->fetchFirstColumn($this->pendingSql().' ORDER BY s.id LIMIT '.$limit);
            foreach ($parents as $parent) {
                $memory = json_decode((string) $this->connection->fetchOne("SELECT json_extract(metadata, '$.source') FROM om_semantic_document WHERE id = ?", [$parent]), true, flags: \JSON_THROW_ON_ERROR);
                $key = $memory['kind'].':'.$memory['id'];
                $indexed = array_fill_keys($this->connection->fetchFirstColumn("SELECT id FROM om_semantic_document WHERE json_extract(metadata, '$._parent_id') = ?", [$key]), true);
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
                $this->saveState($sourceHash, $dimensions, false, false);
            }
            // Observer commits can occur while this worker embeds. Compare the
            // exact source snapshot, not a database-wide change counter.
            $complete = false === $this->connection->fetchOne($this->pendingSql().' LIMIT 1')
                && $sourceHash === $this->sourceHash($this->refreshSources());
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
                throw $this->building($this->memories(), $state);
            }
            $memories = 0 === (int) $state['dimensions'] ? $this->memories() : $this->refreshSources();
            if ([] === $memories) {
                return ['results' => [], 'truncated' => false, 'partial' => false];
            }
            if (0 === (int) $state['dimensions']) {
                throw $this->building($memories, $state);
            }
            $snapshotHash = $this->sourceHash($memories);
            $partial = 0 === (int) $state['complete'] || $state['source_hash'] !== $snapshotHash;
            // Network calls must not hold the writer lock. Revalidate the exact
            // source/index snapshot after embedding before reading either store.
            $lock->release();
            $checkpoint();
            $vector = $this->client->embed([$query], query: true)[0];
            $checkpoint();
            $lock = $this->lock();
            $currentState = $this->state();
            $currentMemories = $this->refreshSources();
            if ($state !== $currentState || $snapshotHash !== $this->sourceHash($currentMemories)) {
                throw $this->building($currentMemories, $currentState);
            }
            if ($vector->getDimensions() !== (int) $state['dimensions']) {
                $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                throw new SemanticSearchException('embedding_dimensions_changed', 'Query embedding dimensions changed; the semantic index must rebuild.');
            }
            $filtered = [];
            foreach ($memories as $key => $memory) {
                [$after, $before] = $dates[$memory['kind']];
                if ((null === $after || $memory['timestamp'] >= $after) && (null === $before || $memory['timestamp'] <= $before)) {
                    $filtered[$key] = true;
                }
            }
            $hasDates = \count($filtered) !== \count($memories);
            $chunkIds = [];
            $filter = ($hasDates || $partial) ? function (VectorDocumentInterface $document) use ($filtered, $memories, $hasDates, $partial, &$chunkIds): bool {
                $parent = (string) $document->getMetadata()->getParentId();
                if (!isset($memories[$parent]) || ($hasDates && !isset($filtered[$parent]))) {
                    return false;
                }
                if (!$partial) {
                    return true;
                }
                if (!isset($chunkIds[$parent])) {
                    $chunkIds[$parent] = array_fill_keys($this->connection->fetchFirstColumn("SELECT c.value FROM om_semantic_document s, json_each(s.metadata, '$.chunks') c WHERE s.id = ?", ['source:'.$parent]), true);
                }

                return isset($chunkIds[$parent][$document->getId()]);
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
                if (!isset($memories[$parent])) {
                    throw new \RuntimeException('Semantic result has no canonical memory.');
                }
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

    private function rebuild(TextStore $text, bool $initial): void
    {
        $this->saveState('', 0, true, false);
        (new Filesystem())->remove($this->directory.'/vektor');
        $text->clear();
        $this->saveState('', 0, false, false);
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

    /** @param array<string, array<string, mixed>> $memories
     * @param array<string, mixed>|null $state
     */
    private function building(array $memories, ?array $state): SemanticSearchException
    {
        $indexedCount = 0;
        if (null !== $state && 0 === (int) $state['dirty'] && $state['signature'] === $this->settings->signature()
            && false !== $this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'om_semantic_document'")) {
            $parents = array_fill_keys(array_map('strval', $this->connection->fetchFirstColumn("SELECT DISTINCT json_extract(metadata, '$._parent_id') FROM om_semantic_document WHERE id NOT LIKE 'source:%'")), true);
            $indexedCount = \count(array_intersect_key($memories, $parents));
        }

        return new SemanticSearchException('index_building', 'Semantic index is building or stale; counts show source memories represented by at least one indexed chunk, not search readiness.', ['indexed_count' => $indexedCount, 'source_count' => \count($memories)]);
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

    /** @return array<string, array<string, mixed>> */
    private function memories(bool $signatures = false): array
    {
        $sql = 'SELECT s.kind, s.id, s.session_id, s.timestamp, s.importance';
        if ($signatures) {
            $sql .= ", json_extract(d.metadata, '$.signature') AS signature";
        }
        $sql .= ' FROM ('.$this->sourceSql().') s';
        if ($signatures) {
            $sql .= " JOIN om_semantic_document d ON d.id = 'source:' || s.kind || ':' || s.id";
        }
        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY s.kind, s.id');
        $memories = [];
        foreach ($rows as $row) {
            $memories[$row['kind'].':'.$row['id']] = $row;
        }

        return $memories;
    }

    /**
     * Source manifests live only in the disposable TextStore metadata. Compare
     * exact fields inside SQLite; unchanged content never crosses into PHP.
     * Unlike chunk rows, manifests have no FTS text and never enter Vektor.
     *
     * @return array<string, array<string, mixed>>
     */
    private function refreshSources(): array
    {
        return $this->connection->transactional(function (): array {
            $source = $this->sourceSql();
            $equal = [];
            foreach (['kind', 'id', 'session_id', 'timestamp', 'importance', 'content'] as $field) {
                $equal[] = "json_extract(d.metadata, '$.source.".$field."') IS s.".$field;
            }
            $sql = 'SELECT s.* FROM ('.$source.") s LEFT JOIN om_semantic_document d ON d.id = 'source:' || s.kind || ':' || s.id WHERE d.id IS NULL OR NOT (".implode(' AND ', $equal).')';
            foreach ($this->connection->iterateAssociative($sql) as $memory) {
                if (null === $memory['importance']) {
                    unset($memory['importance']);
                }
                $parent = $memory['kind'].':'.$memory['id'];
                $metadata = ['source' => $memory, 'signature' => hash('sha256', json_encode($memory, \JSON_THROW_ON_ERROR)), 'chunks' => array_keys($this->chunks([$parent => $memory]))];
                // Metadata-only writes use DBAL so the source comparison and
                // manifest publication share one SQLite transaction. TextStore
                // add() owns a native transaction and cannot be nested here.
                $this->connection->executeStatement('INSERT INTO om_semantic_document (id, vector, metadata) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET metadata = excluded.metadata', ['source:'.$parent, '[1.0]', json_encode($metadata, \JSON_THROW_ON_ERROR)]);
            }
            $deleted = $this->connection->fetchFirstColumn("SELECT id FROM om_semantic_document WHERE id LIKE 'source:%' AND id NOT IN (SELECT 'source:' || kind || ':' || id FROM (".$source.'))');
            // Unchanged searches remain read-only SQLite transactions. Even a
            // DELETE matching no rows would unnecessarily acquire a writer lock.
            if ([] !== $deleted) {
                $this->connection->executeStatement('DELETE FROM om_semantic_document WHERE id IN (?)', [$deleted], [ArrayParameterType::STRING]);
            }

            return $this->memories(true);
        });
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

    /** @param array<string, array<string, mixed>> $memories */
    private function sourceHash(array $memories): string
    {
        return hash('sha256', json_encode($memories, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $state = $this->connection->fetchAssociative('SELECT * FROM om_semantic_state WHERE id = 1');

        return false === $state ? null : $state;
    }

    private function saveState(string $hash, int $dimensions, bool $dirty, bool $complete): void
    {
        $this->connection->executeStatement('INSERT INTO om_semantic_state (id, signature, source_hash, dimensions, dirty, complete) VALUES (1, ?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET signature = excluded.signature, source_hash = excluded.source_hash, dimensions = excluded.dimensions, dirty = excluded.dirty, complete = excluded.complete', [$this->settings->signature(), $hash, $dimensions, (int) $dirty, (int) $complete]);
    }
}
