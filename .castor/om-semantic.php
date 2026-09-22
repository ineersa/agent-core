<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticApiClient;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexService;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Request counts only: never retain HTTP bodies or memory-derived input. */
final class OmBenchmarkHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    /** @param ArrayObject<string, int> $counts */
    public function __construct(public readonly ArrayObject $counts)
    {
        $this->client = HttpClient::create(['timeout' => 10, 'max_duration' => 10]);
    }

    /** @param array<mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $this->counts[$path] = ($this->counts[$path] ?? 0) + 1;

        return $this->client->request($method, $url, $options);
    }
}

/**
 * Source is opened read-only and backed up through SQLite's consistent backup API.
 * Output is a metadata-only JSON report; private artifacts stay in an owned temp
 * directory printed at startup. No live database or session is modified.
 */
#[AsTask(name: 'om:benchmark-semantic', description: 'Benchmark full-corpus OM hybrid retrieval on a disposable SQLite backup and local endpoints')]
function om_benchmark_semantic(string $source, string $output): void
{
    if (!is_file($source)) {
        throw new InvalidArgumentException('Source OM database does not exist.');
    }
    if (file_exists($output)) {
        throw new InvalidArgumentException('Choose a new output report path.');
    }
    $directory = TestDirectoryIsolation::createProjectTempDir('om-semantic-benchmark');
    (new Filesystem())->chmod($directory, 0o700);
    $path = $directory.'/om.sqlite';
    $reader = new SQLite3($source, \SQLITE3_OPEN_READONLY);
    $copy = new SQLite3($path);
    try {
        if (!$reader->backup($copy)) {
            throw new RuntimeException('Unable to create a consistent read-only-source backup.');
        }
    } finally {
        $reader->close();
        $copy->close();
    }
    echo 'private_artifacts='.$directory.\PHP_EOL;
    $connection = OmDatabaseFactory::connectAndMigrate($path);
    /** @var ArrayObject<string, int> $counts */
    $counts = new ArrayObject();
    $http = new OmBenchmarkHttpClient($counts);
    $config = [
        'embedding_api' => [
            'base_url' => 'http://localhost:8059/v1', 'model_id' => 'coderankembed-q8_0.gguf',
            'query_prefix' => 'Represent this query for searching relevant code:',
            'chunk_bytes' => 1200, 'overlap_bytes' => 192, 'max_lines' => 80, 'batch_size' => 4,
        ],
    ];
    $settings = SemanticSettings::fromArray($config) ?? throw new LogicException('Missing benchmark settings.');
    try {
        // A backup can itself contain derived tables; force a genuine full build.
        $connection->executeStatement('DELETE FROM om_semantic_state');
        $observations = (int) $connection->fetchOne('SELECT COUNT(*) FROM om_observation');
        $reflections = (int) $connection->fetchOne('SELECT COUNT(*) FROM om_reflection');
        $groundTruth = $connection->fetchAllAssociative("SELECT observation_id AS id, run_id AS session_id FROM om_observation WHERE run_id = '32' AND (content LIKE '%2510%' OR content LIKE '%MapToolArguments%') ORDER BY observation_id");
        if ([] === $groundTruth) {
            throw new RuntimeException('The source lacks the expected session-32 MapToolArguments memories.');
        }
        $start = microtime(true);
        $maximumBatchSeconds = 0.0;
        $batchCount = 0;
        do {
            $batchStart = microtime(true);
            // Fresh service per batch exercises production restart/progress logic.
            $index = new SemanticIndexService($connection, $path, $settings, new SemanticApiClient($settings, $http));
            $complete = $index->synchronize();
            $maximumBatchSeconds = max($maximumBatchSeconds, microtime(true) - $batchStart);
            ++$batchCount;
            if (0 === $batchCount % 100) {
                echo json_encode(['batches' => $batchCount, 'seconds' => microtime(true) - $start], \JSON_THROW_ON_ERROR).\PHP_EOL;
            }
            if (microtime(true) - $start > 3300) {
                throw new RuntimeException('Benchmark exceeded its 55-minute safety budget. Private checkpoint artifacts were retained.');
            }
        } while (!$complete);
        $buildSeconds = microtime(true) - $start;
        $buildRequests = $counts->getArrayCopy();
        $chunkCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document');
        $dimension = (int) $connection->fetchOne('SELECT dimensions FROM om_semantic_state WHERE id = 1');
        $noWorkStart = microtime(true);
        if (!$index->synchronize() || $buildRequests !== $counts->getArrayCopy()) {
            throw new RuntimeException('A completed unchanged index must not re-embed.');
        }
        $noWorkSeconds = microtime(true) - $noWorkStart;
        $queries = [
            'identifier' => 'MapToolArguments',
            'pr_number' => '2510',
            'paraphrase' => 'where we upstreamed flat DTO arguments',
            'concept' => 'Symfony AI tool arguments flattened from a data transfer object instead of nested JSON',
            'negative' => 'quasar glacier pineapple zyxwv-no-such-memory-71a9',
        ];
        $results = [];
        $dates = ['observation' => [null, null], 'reflection' => [null, null]];
        foreach ([false, true] as $rerank) {
            $mode = $rerank ? 'reranked' : 'hybrid';
            $modeConfig = $config;
            if ($rerank) {
                $modeConfig['reranker_api'] = ['base_url' => 'http://localhost:8060/v1', 'model_id' => 'bge-reranker-base-q8_0.gguf', 'batch_size' => 8, 'document_characters' => 768];
            }
            $modeSettings = SemanticSettings::fromArray($modeConfig) ?? throw new LogicException('Missing settings.');
            $service = new SemanticIndexService($connection, $path, $modeSettings, new SemanticApiClient($modeSettings, $http));
            foreach ($queries as $label => $query) {
                $before = $counts->getArrayCopy();
                $queryStart = microtime(true);
                $retrieval = $service->search($query, $dates, static function (): void {});
                $seconds = microtime(true) - $queryStart;
                $hits = array_slice($retrieval['results'], 0, 20);
                $ranks = [];
                foreach ($hits as $offset => $hit) {
                    foreach ($groundTruth as $expected) {
                        if ($hit['id'] === $expected['id'] && $hit['session_id'] === $expected['session_id']) {
                            $ranks[] = $offset + 1;
                        }
                    }
                }
                $calls = [];
                foreach ($counts as $endpoint => $count) {
                    $calls[$endpoint] = $count - ($before[$endpoint] ?? 0);
                }
                $results[$mode][$label] = [
                    'seconds' => $seconds, 'count' => count($hits), 'truncated' => $retrieval['truncated'],
                    'expected_session_32_ranks' => $ranks, 'requests' => $calls,
                    'hits' => array_map(static fn (array $hit): array => ['kind' => $hit['kind'], 'session_id' => $hit['session_id'], 'id' => $hit['id']], $hits),
                ];
                echo json_encode(['mode' => $mode, 'query' => $label, 'seconds' => $seconds, 'expected_ranks' => $ranks], \JSON_THROW_ON_ERROR).\PHP_EOL;
            }
        }
        $connection->executeStatement('PRAGMA wal_checkpoint(TRUNCATE)');
        clearstatcache();
        $fileSizes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $fileSizes[substr($file->getPathname(), strlen($directory) + 1)] = $file->getSize();
            }
        }
        $report = [
            'observations' => $observations, 'reflections' => $reflections, 'chunks' => $chunkCount,
            'dimensions' => $dimension, 'build_seconds' => $buildSeconds, 'batches' => $batchCount,
            'maximum_batch_seconds' => $maximumBatchSeconds, 'build_requests' => $buildRequests,
            'no_work_seconds' => $noWorkSeconds, 'no_work_requests' => 0,
            'peak_memory_bytes' => memory_get_peak_usage(true), 'file_sizes' => $fileSizes,
            'expected_session_32_memories' => $groundTruth, 'queries' => $results,
        ];
        (new Filesystem())->dumpFile($output, json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR).\PHP_EOL);
        echo 'report='.$output.\PHP_EOL;
    } finally {
        $connection->close();
    }
}
