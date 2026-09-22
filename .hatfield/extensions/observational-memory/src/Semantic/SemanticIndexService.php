<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Store\Bridge\Sqlite\Store as TextStore;
use Symfony\AI\Store\Bridge\Vektor\Store as VectorStore;
use Symfony\AI\Store\CombinedStore;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
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
            $memories = $this->memories();
            $chunks = $this->chunks($memories);
            $sourceHash = $this->sourceHash($memories);
            $state = $this->state();
            $text = TextStore::fromDbal($this->connection, 'om_semantic_document');
            $textExists = 2 === (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('om_semantic_document', 'om_semantic_document_fts')");
            $text->setup();
            $indexed = array_fill_keys(array_map('strval', $this->connection->fetchFirstColumn('SELECT id FROM om_semantic_document')), true);
            $reset = null === $state || 1 === (int) $state['dirty'] || $state['signature'] !== $this->settings->signature()
                || !$textExists || \count($indexed) !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document_fts')
                || [] !== array_diff_key($indexed, $chunks) || (!$this->filesExist() && [] !== $indexed);
            if ($reset) {
                $initial = null === $state;
                $this->saveState($sourceHash, 0, true, false);
                // Never soft-delete Vektor nodes: tombstones can exhaust its fixed
                // candidate buffer and return no results while live records remain.
                (new Filesystem())->remove($this->directory.'/vektor');
                $text->clear();
                $indexed = [];
                $state = null;
                $this->saveState($sourceHash, 0, false, false);
                $this->logger->log($initial ? 'info' : 'warning', 'om.semantic.rebuild', ['run_id' => $this->runId, 'session_id' => $this->runId, 'component' => 'observational_memory', 'event_type' => 'om.semantic.rebuild']);
            }
            $dimensions = (int) ($state['dimensions'] ?? 0);
            $pending = array_diff_key($chunks, $indexed);
            $this->saveState($sourceHash, $dimensions, false, false);
            if ([] !== $pending) {
                // Also cap work independently of provider batch configuration.
                $batch = \array_slice($pending, 0, min(4, $this->settings->embeddingBatchSize), true);
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
                $vector = $this->vectorStore($dimensions);
                $vector->add($documents);
                (new MemoryStoreAdapter($text, true, 0))->add($documents);
                $this->saveState($sourceHash, $dimensions, false, false);
                $pending = array_diff_key($pending, $batch);
            }
            // Observer commits can occur while this worker embeds. Never call a
            // snapshot complete if its canonical source changed during that call.
            $complete = [] === $pending && $sourceHash === $this->sourceHash($this->memories());
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
     * @return array{results: list<Memory>, truncated: bool}
     */
    public function search(string $query, array $dates, \Closure $checkpoint): array
    {
        $lock = $this->lock();
        try {
            $memories = $this->memories();
            $state = $this->state();
            if (null === $state || 0 === (int) $state['complete'] || 1 === (int) $state['dirty'] || $state['signature'] !== $this->settings->signature() || $state['source_hash'] !== $this->sourceHash($memories)) {
                throw $this->building($memories, $state);
            }
            if ([] === $memories || 0 === (int) $state['dimensions']) {
                return ['results' => [], 'truncated' => false];
            }
            // Network calls must not hold the writer lock. Revalidate the exact
            // source/index snapshot after embedding before reading either store.
            $lock->release();
            $checkpoint();
            $vector = $this->client->embed([$query], query: true)[0];
            $checkpoint();
            $lock = $this->lock();
            $currentState = $this->state();
            $currentMemories = $this->memories();
            if ($state !== $currentState || $state['source_hash'] !== $this->sourceHash($currentMemories)) {
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
            $filter = $hasDates ? static fn (VectorDocument $document): bool => isset($filtered[(string) $document->getMetadata()->getParentId()]) : null;
            try {
                if (!$this->filesExist()) {
                    $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                    throw new \RuntimeException('Semantic vector files are missing.');
                }
                $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document');
                $vectorStore = new MemoryStoreAdapter($this->vectorStore((int) $state['dimensions']), false, $count, $filter);
                $textStore = new MemoryStoreAdapter(TextStore::fromDbal($this->connection, 'om_semantic_document'), true, $count, $filter);
                $store = new CombinedStore($vectorStore, $textStore);
                $hits = \array_slice(iterator_to_array($store->query(new HybridQuery($vector, $query), ['maxItems' => 100]), false), 0, 100);
            } catch (\Throwable $error) {
                // SQLite explicitly identifies corruption. Busy/locked databases
                // and other transient failures must not discard valid embeddings.
                if (($error instanceof \PDOException && \in_array((int) ($error->errorInfo[1] ?? 0) & 0xFF, [11, 26], true))
                    || ($error instanceof \RuntimeException && 1 === preg_match('/^Node [0-9]+ not found in graph$/D', $error->getMessage()))) {
                    $this->saveState($state['source_hash'], (int) $state['dimensions'], true, false);
                }
                throw $error;
            }
            // Hits and canonical source rows are materialized. Their immutable
            // snapshot can be reranked without blocking asynchronous indexing.
            $lock->release();
            $checkpoint();
            if (null !== $this->settings->rerankerUrl && [] !== $hits) {
                $order = $this->client->rerank($query, array_map(static fn (VectorDocument $hit): string => $hit->getMetadata()->getText() ?? '', $hits), $checkpoint);
                $hits = array_map(static fn (int $index): VectorDocument => $hits[$index], $order);
            }
            $results = [];
            foreach ($hits as $hit) {
                $parent = (string) $hit->getMetadata()->getParentId();
                if (!isset($memories[$parent])) {
                    throw new \RuntimeException('Semantic result has no canonical memory.');
                }
                $results[$parent] ??= $memories[$parent];
            }
            $checkpoint();

            return ['results' => array_values($results), 'truncated' => \count($hits) >= 100 || $vectorStore->wasTruncated() || $textStore->wasTruncated()];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, Memory> $memories
     * @param array<string, mixed>|null $state
     */
    private function building(array $memories, ?array $state): SemanticSearchException
    {
        $indexedCount = 0;
        if (null !== $state && 0 === (int) $state['dirty'] && $state['signature'] === $this->settings->signature()
            && false !== $this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'om_semantic_document'")) {
            $parents = array_fill_keys(array_map('strval', $this->connection->fetchFirstColumn("SELECT DISTINCT json_extract(metadata, '$._parent_id') FROM om_semantic_document")), true);
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

    /** @return array<string, Memory> */
    private function memories(): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT 'observation' AS kind, observation_id AS id, run_id AS session_id, content, timestamp, relevance AS importance FROM om_observation UNION ALL SELECT 'reflection' AS kind, reflection_id AS id, run_id AS session_id, content, created_at AS timestamp, NULL AS importance FROM om_reflection ORDER BY kind, id");
        $memories = [];
        foreach ($rows as $row) {
            $memory = ['kind' => (string) $row['kind'], 'session_id' => (string) $row['session_id'], 'id' => (string) $row['id'], 'timestamp' => (string) $row['timestamp'], 'content' => (string) $row['content']];
            if (null !== $row['importance']) {
                $memory['importance'] = (string) $row['importance'];
            }
            $memories[$memory['kind'].':'.$memory['id']] = $memory;
        }

        return $memories;
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
                // 32 hexadecimal characters fit Vektor's 36-byte external-ID field.
                $id = substr(hash('sha256', $parent.'\0'.$position.'\0'.$text), 0, 32);
                $chunks[$id] = ['parent' => $parent, 'text' => $text];
            }
        }

        return $chunks;
    }

    /** @param array<string, Memory> $memories */
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
