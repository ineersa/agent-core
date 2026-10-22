<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticApiClient;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class SemanticIndexServiceTest extends IsolatedKernelTestCase
{
    private string $directory;
    private string $path;
    private Connection $connection;
    private OmSettings $settings;
    private MockHttpClient $http;
    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = TestDirectoryIsolation::createProjectTempDir('om-semantic');
        $this->path = $this->directory.'/om.sqlite';
        $this->connection = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($this->path);
        $this->settings = $this->settings();
        $this->http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $this->requests[] = $body;
            self::assertSame('POST', $method);
            self::assertSame('http://embeddings.test/v1/embeddings', $url);
            $data = [];
            foreach ($body['input'] as $i => $text) {
                $data[] = ['index' => $i, 'embedding' => str_contains($text, 'alpha') && !str_contains($text, 'Represent') ? [1.0, 0.0] : [0.0, 1.0]];
            }

            return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
        });
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function hybridFusionUsesKeywordsAndSemanticCandidatesWithCanonicalProvenance(): void
    {
        $id = $this->observation('alpha upstream contribution', '32', '2026-09-12 12:00');
        $this->observation('unrelated text', '63', '2026-09-20 12:00');
        $this->assertTrue($this->index()->synchronize());
        $result = $this->query()->search('"alpha" OR ("upstream")');
        $this->assertTrue($result['ok']);
        $this->assertSame($id, $result['results'][0]['id']);
        $this->assertSame('32', $result['results'][0]['session_id']);
        $this->assertSame(substr($id, 0, 12), $result['results'][0]['display_id']);
        $this->assertSame('high', $result['results'][0]['importance']);
        $this->assertSame(['Represent this query for searching relevant code: "alpha" OR ("upstream")'], $this->requests[1]['input']);
        $this->assertEqualsCanonicalizing(['alpha upstream contribution', 'unrelated text'], $this->requests[0]['input']);
        $this->assertSame(2, $result['count']);
        $filtered = $this->query()->search('alpha', before: '2026-09-12');
        $this->assertSame([$id], array_column($filtered['results'], 'id'));
        $this->assertSame(1, $filtered['count']);
    }

    #[Test]
    public function backfillResumesInBoundedBatchesAndSearchesCleanIndexedMemories(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->observation('memory '.$i);
        }
        $initial = $this->query()->search('memory');
        $this->assertSame('index_building', $initial['error']);
        $this->assertSame(0, $initial['indexed_count']);
        $this->assertSame(6, $initial['source_count']);
        $this->assertFalse($this->index()->synchronize());
        $this->assertSame(4, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM om_semantic_document WHERE id NOT LIKE 'source:%'"));
        $this->assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document_fts'));
        $progress = $this->query()->search('memory');
        $this->assertTrue($progress['ok']);
        $this->assertTrue($progress['partial']);
        $this->assertSame(4, $progress['count']);
        // New service instances model successive worker jobs, not a cached cursor.
        $this->assertTrue($this->index()->synchronize());
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame([4, 1, 2], array_map(static fn (array $r): int => \count($r['input']), $this->requests));
        $this->assertFalse($this->query()->search('memory')['partial']);
        $this->observation('late memory');
        $stale = $this->query()->search('memory');
        $this->assertTrue($stale['ok'], json_encode($stale, \JSON_THROW_ON_ERROR));
        $this->assertTrue($stale['partial']);
        $this->assertSame(6, $stale['count']);
        $this->assertTrue($this->index()->synchronize());
        $refreshed = $this->query()->search('memory');
        $this->assertFalse($refreshed['partial']);
        $this->assertSame(7, $refreshed['count']);
    }

    #[Test]
    public function interruptedCrossStoreWriteRebuildsWithoutDuplicateIds(): void
    {
        $id = $this->observation('alpha');
        // Fail the real text-store write AFTER Vektor has accepted the document.
        $this->connection->executeStatement('CREATE TABLE om_semantic_document (id TEXT PRIMARY KEY, vector TEXT NOT NULL, metadata TEXT)');
        $this->connection->executeStatement("CREATE TRIGGER fail_index BEFORE INSERT ON om_semantic_document WHEN NEW.id NOT LIKE 'source:%' BEGIN SELECT RAISE(ABORT, 'injected storage failure'); END");
        try {
            $this->index()->synchronize();
            $this->fail('Storage failure must propagate.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('injected storage failure', $error->getMessage());
        }
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT dirty FROM om_semantic_state'));
        $this->assertSame('index_building', $this->query()->search('alpha')['error']);
        $this->connection->executeStatement('DROP TRIGGER fail_index');
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame([$id], array_column($this->query()->search('alpha')['results'], 'id'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_observation'));
    }

    #[Test]
    public function deletionAndModelChangeRebuildDerivedData(): void
    {
        $removed = $this->observation('alpha removed');
        $kept = $this->observation('retained memory');
        $this->assertTrue($this->index()->synchronize());
        $this->connection->delete('om_observation', ['observation_id' => $removed]);
        $stale = $this->query()->search('alpha');
        $this->assertTrue($stale['partial']);
        $this->assertSame([$kept], array_column($stale['results'], 'id'));
        $this->assertSame([$kept], array_column($this->query()->search('memory')['results'], 'id'));
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame([$kept], array_column($this->query()->search('memory')['results'], 'id'));
        $this->settings = $this->settings('replacement-embedding-model');
        $this->assertSame('index_building', $this->query()->search('memory')['error']);
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame('replacement-embedding-model', $this->requests[array_key_last($this->requests)]['model']);
        $this->assertSame([$kept], array_column($this->query()->search('memory')['results'], 'id'));
    }

    #[Test]
    public function changedMemoryIsExcludedUntilItsNewChunksAreIndexed(): void
    {
        $changed = $this->observation('alpha original');
        $kept = $this->observation('alpha retained');
        $older = $this->observation('alpha older', '32', '2026-09-11 12:00');
        $this->assertTrue($this->index()->synchronize());
        $this->connection->update('om_observation', ['content' => 'beta replacement'], ['observation_id' => $changed]);
        $stale = $this->query()->search('alpha');
        $this->assertTrue($stale['partial']);
        $this->assertEqualsCanonicalizing([$kept, $older], array_column($stale['results'], 'id'));
        $dated = $this->query()->search('alpha', after: '2026-09-12');
        $this->assertTrue($dated['partial']);
        $this->assertSame([$kept], array_column($dated['results'], 'id'));
        $this->assertTrue($this->index()->synchronize());
        $refreshed = $this->query()->search('beta');
        $this->assertFalse($refreshed['partial']);
        $this->assertContains($changed, array_column($refreshed['results'], 'id'));
    }

    #[Test]
    public function sequentialProjectsResetVendorGlobalConfiguration(): void
    {
        $first = $this->observation('alpha project one');
        $firstIndex = $this->index();
        $this->assertTrue($firstIndex->synchronize());
        $otherPath = $this->directory.'/other.sqlite';
        $otherConnection = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($otherPath);
        try {
            $otherSettings = $this->settings->semantic;
            $this->assertNotNull($otherSettings);
            $otherHttp = new MockHttpClient(new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0,0.5]}]}'));
            $other = new SemanticIndexService($otherConnection, $otherPath, $otherSettings, new SemanticApiClient($otherSettings, $otherHttp));
            // Copy a canonical row into a distinct project, then give it another identity.
            $row = $this->connection->fetchAssociative('SELECT * FROM om_observation');
            $row['observation_id'] = str_repeat('e', 64);
            $otherConnection->insert('om_observation', $row);
            $this->assertTrue($other->synchronize());
            $second = $this->observation('alpha project one follow-up');
            $this->assertTrue($firstIndex->synchronize());
            $this->assertEqualsCanonicalizing([$first, $second], array_column($this->query()->search('alpha')['results'], 'id'));
        } finally {
            $otherConnection->close();
        }
    }

    #[Test]
    public function configuredEmbeddingFailureDoesNotFallBackToExactMatchesOrLogContent(): void
    {
        $this->observation('private alpha needle');
        $this->assertTrue($this->index()->synchronize());
        $logger = new TestLogger();
        $broken = new MockHttpClient(new MockResponse('private alpha needle', ['http_code' => 500]));
        $result = $this->query($broken, $logger)->search('private alpha needle');
        $this->assertFalse($result['ok']);
        $this->assertSame('embedding_unavailable', $result['error']);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertStringNotContainsString('private alpha needle', json_encode($logger->records));
    }

    #[Test]
    public function rerankingOverridesFusionAndCollapsesChunkHitsToOneMemory(): void
    {
        $this->observation(str_repeat('alpha ', 250));
        $other = $this->observation('other');
        $this->assertTrue($this->index()->synchronize());
        $this->settings = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank'],
        ]]);
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/embeddings')) {
                return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
            }
            $this->assertTrue($this->index()->synchronize(), 'Reranking must not hold the index writer lock.');
            $documents = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['documents'];
            $scores = [];
            foreach ($documents as $i => $text) {
                $scores[] = ['index' => $i, 'relevance_score' => 'other' === $text ? 1.0 : 0.0];
            }

            return new MockResponse(json_encode(['results' => $scores], \JSON_THROW_ON_ERROR));
        });
        $result = $this->query($http)->search('alpha');
        $this->assertTrue($result['ok']);
        $this->assertSame($other, $result['results'][0]['id']);
        $this->assertSame(2, $result['count']);
    }

    #[Test]
    public function rerankerFailureDoesNotReturnOtherwiseValidFusionResults(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $this->settings = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank'],
        ]]);
        $http = new MockHttpClient([
            new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}'),
            new MockResponse('private server response', ['http_code' => 503]),
        ]);
        $result = $this->query($http)->search('alpha');
        $this->assertSame('reranker_unavailable', $result['error']);
        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertStringNotContainsString('private server response', json_encode($result));
    }

    #[Test]
    public function rejectedRerankerChunksDoNotReappearAfterParentCollapse(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $this->settings = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank', 'min_score' => -4],
        ]]);
        $http = new MockHttpClient([
            new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}'),
            new MockResponse('{"results":[{"index":0,"relevance_score":-4.01}]}'),
        ]);
        $result = $this->query($http)->search('alpha');
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['results']);
    }

    #[Test]
    public function rerankerCanRecoverLexicalCandidatesBeyondTheFirstHundredFusedChunks(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $this->observation('alpha lexical '.$i);
            $this->observation('omega semantic '.$i);
        }
        // Four chunks per call need 50 batches; allow one extra and fail fast.
        $complete = false;
        for ($i = 0; $i < 51 && !$complete; ++$i) {
            $complete = $this->index()->synchronize();
        }
        $this->assertTrue($complete);
        $this->settings = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank', 'min_score' => 1],
        ]]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/embeddings')) {
                return new MockResponse('{"data":[{"index":0,"embedding":[0.0,1.0]}]}');
            }
            $documents = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['documents'];
            $scores = [];
            foreach ($documents as $i => $text) {
                $scores[] = ['index' => $i, 'relevance_score' => str_starts_with($text, 'alpha') ? 1 : 0];
            }

            return new MockResponse(json_encode(['results' => $scores], \JSON_THROW_ON_ERROR));
        });
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        $result = (new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $http)))->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
        $this->assertCount(100, $result['results']);
        $this->assertTrue($result['truncated']);

        $strict = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank', 'min_score' => 2],
        ]])->semantic;
        $this->assertNotNull($strict);
        $rejected = (new SemanticIndexService($this->connection, $this->path, $strict, new SemanticApiClient($strict, $http)))->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
        $this->assertSame([], $rejected['results']);
        $this->assertTrue($rejected['truncated']);
    }

    #[Test]
    public function reflectionHitsKeepRecallIdentityAndDateSemantics(): void
    {
        $id = str_repeat('f', 64);
        $this->connection->insert('om_reflection', [
            'reflection_id' => $id, 'run_id' => 'other-session', 'compaction_request_id' => 'request',
            'observation_set_hash' => 'set', 'content' => 'alpha reflection', 'supporting_observation_ids_json' => '[]',
            'compression_level' => 'normal', 'token_count' => 3, 'reflector_model' => 'test/model',
            'reflector_schema_version' => '1', 'created_at' => '2026-09-12T12:00:00+00:00',
        ]);
        $this->assertTrue($this->index()->synchronize());
        $result = $this->query()->search('alpha', after: '2026-09-12', before: '2026-09-12');
        $this->assertTrue($result['ok']);
        $this->assertSame('reflection', $result['results'][0]['kind']);
        $this->assertSame($id, $result['results'][0]['id']);
        $this->assertSame('other-session', $result['results'][0]['session_id']);
        $this->assertArrayNotHasKey('importance', $result['results'][0]);
        $recall = $this->query()->recall('current-session', $result['results'][0]['id'], sessionId: $result['results'][0]['session_id']);
        $this->assertTrue($recall['ok']);
        $this->assertSame('other-session', $recall['session_id']);
        $this->assertSame('alpha reflection', $recall['content']);
        $this->assertSame(0, $this->query()->search('alpha', after: '2026-09-13')['count']);
    }

    #[Test]
    public function cancellationAfterQueryEmbeddingReturnsControlFlagsRatherThanMatches(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $cancelled = false;
        $token = $this->createStub(ToolCancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$cancelled): bool {
            return $cancelled;
        });
        $http = new MockHttpClient(static function () use (&$cancelled): MockResponse {
            $cancelled = true;

            return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
        });
        $result = $this->query($http)->search('alpha', cancellationToken: $token);
        $this->assertTrue($result['cancelled']);
        $this->assertArrayNotHasKey('results', $result);
    }

    #[Test]
    public function anotherProcessCannotReadOrWriteAnIndexWhileAWriterOwnsIt(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $input = new InputStream();
        $script = <<<'PHP'
            require $argv[1];
            $lock = (new Symfony\Component\Lock\LockFactory(new Symfony\Component\Lock\Store\FlockStore($argv[2])))->createLock('semantic-index');
            $lock->acquire(true);
            fwrite(STDOUT, "locked\n");
            fgets(STDIN);
            $lock->release();
            PHP;
        $process = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4).'/vendor/autoload.php', $this->path.'.semantic'], env: ['HATFIELD_SESSION_ID' => false], input: $input, timeout: 5);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'locked')));
            $this->assertSame('index_busy', $this->query()->search('alpha')['error']);
            try {
                $this->index()->synchronize();
                $this->fail('Concurrent index write must fail visibly.');
            } catch (\Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSearchException $error) {
                $this->assertSame('index_busy', $error->failureCode);
            }
        } finally {
            $input->write("release\n");
            $input->close();
            try {
                $process->wait();
            } finally {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
        $this->assertSame(0, $process->getExitCode());
        $this->assertTrue($this->query()->search('alpha')['ok']);
    }

    #[Test]
    public function transientVendorReadFailurePreservesHealthyEmbeddings(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $pdo = $this->connection->getNativeConnection();
        $this->assertInstanceOf(\PDO::class, $pdo);
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [Support\TransientFtsStatement::class]);
        try {
            $this->index()->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
            $this->fail('Transient read failure must propagate.');
        } catch (\PDOException $error) {
            $this->assertSame(5, $error->errorInfo[1]);
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous);
        }
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT dirty FROM om_semantic_state'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT complete FROM om_semantic_state'));
        $requests = \count($this->requests);
        $this->assertTrue($this->index()->synchronize());
        $this->assertCount($requests, $this->requests);
        $this->assertTrue($this->query()->search('alpha')['ok']);
    }

    #[Test]
    public function embeddingDoesNotHoldIndexLockAndRevalidatesChangedSource(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $http = new MockHttpClient(function (): MockResponse {
            $this->observation('new concurrent memory');
            $this->assertTrue($this->index()->synchronize());

            return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
        });
        $result = $this->query($http)->search('alpha');
        $this->assertSame('index_building', $result['error']);
        $this->assertArrayNotHasKey('results', $result);
        $this->assertTrue($this->query()->search('alpha')['ok']);
    }

    #[Test]
    public function continuationAndUnchangedWorkDoNotLoadCompletedSourceContent(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->observation('alpha source '.$i);
        }
        $pdo = $this->connection->getNativeConnection();
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [Support\SemanticReadStatement::class]);
        Support\SemanticReadStatement::$changedSources = 0;
        Support\SemanticReadStatement::$loadedSources = 0;
        Support\SemanticReadStatement::$chunkLookups = [];
        Support\SemanticReadStatement::$sourceReads = [];
        try {
            $this->assertFalse($this->index()->synchronize());
            $this->assertCount(1, Support\SemanticReadStatement::$sourceReads, 'Full reconstruction streams canonical rows without per-source reads.');
            $this->assertSame(12, Support\SemanticReadStatement::$changedSources);
            Support\SemanticReadStatement::$changedSources = 0;
            Support\SemanticReadStatement::$loadedSources = 0;
            Support\SemanticReadStatement::$chunkLookups = [];
            $this->assertFalse($this->index()->synchronize());
            $this->assertSame(0, Support\SemanticReadStatement::$changedSources);
            $this->assertSame(4, Support\SemanticReadStatement::$loadedSources);
            $this->assertCount(4, Support\SemanticReadStatement::$chunkLookups);
            $plan = $this->connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.Support\SemanticReadStatement::$chunkLookups[0], ['source:observation:fixture']);
            $details = implode("\n", array_column($plan, 'detail'));
            $this->assertMatchesRegularExpression('/SEARCH s .*\(id=\?\)/', $details);
            $this->assertMatchesRegularExpression('/SEARCH d .*\(id=\?\)/', $details);
            $this->assertStringNotContainsString('SCAN s', $details);
            $this->assertStringNotContainsString('SCAN d', $details);
            $this->assertTrue($this->index()->synchronize());
            Support\SemanticReadStatement::$loadedSources = 0;
            $this->assertTrue($this->index()->synchronize());
            $this->assertSame(0, Support\SemanticReadStatement::$changedSources);
            $this->assertSame(0, Support\SemanticReadStatement::$loadedSources);
            $requests = \count($this->requests);
            $result = $this->index()->search('alpha', ['observation' => ['2999', null], 'reflection' => ['2999', null]], static function (): void {});
            $this->assertSame([], $result['results']);
            $this->assertSame(0, Support\SemanticReadStatement::$changedSources);
            $this->assertSame(0, Support\SemanticReadStatement::$loadedSources);
            $this->assertCount($requests + 1, $this->requests);
            $this->observation('alpha new source');
            $this->assertTrue($this->index()->synchronize());
            $this->assertSame(1, Support\SemanticReadStatement::$changedSources);
            $this->assertSame(1, Support\SemanticReadStatement::$loadedSources);
            for ($i = 0; $i < 205; ++$i) {
                $this->observation('alpha incremental source '.$i);
            }
            Support\SemanticReadStatement::$sourceReads = [];
            $this->assertFalse($this->index()->synchronize());
            $this->assertSame(206, Support\SemanticReadStatement::$changedSources);
            $this->assertCount(2, Support\SemanticReadStatement::$sourceReads, '205 changed identities require two joined pages, not per-source reads.');
            foreach (Support\SemanticReadStatement::$sourceReads as $sql) {
                $this->assertStringContainsString('FROM om_source_change c', $sql);
                $this->assertStringContainsString('LIMIT 200', $sql);
            }
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous);
        }
    }

    #[Test]
    public function reflectionContentChangesAreDetectedWithoutAnUpdatedIdentityOrHash(): void
    {
        $id = str_repeat('f', 64);
        $this->connection->insert('om_reflection', [
            'reflection_id' => $id, 'run_id' => 'reflection-session', 'compaction_request_id' => 'request',
            'observation_set_hash' => 'set', 'content' => 'alpha reflection', 'supporting_observation_ids_json' => '[]',
            'compression_level' => 'normal', 'token_count' => 3, 'reflector_model' => 'test/model',
            'reflector_schema_version' => '1', 'created_at' => '2026-09-12T12:00:00+00:00',
        ]);
        $this->assertTrue($this->index()->synchronize());
        $http = new MockHttpClient(function () use ($id): MockResponse {
            $this->connection->update('om_reflection', ['content' => 'omega reflection'], ['reflection_id' => $id]);

            return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
        });
        $this->assertSame('index_building', $this->query($http)->search('alpha')['error']);
        $partial = $this->query()->search('alpha');
        $this->assertTrue($partial['partial']);
        $this->assertSame([], $partial['results']);
        $this->assertTrue($this->index()->synchronize());
        $result = $this->query()->search('omega');
        $this->assertSame('omega reflection', $result['results'][0]['content']);
        $this->assertSame($id, $result['results'][0]['id']);
        $this->assertArrayNotHasKey('signature', $result['results'][0]);
    }

    #[Test]
    public function unrelatedWritesAndRestoredContentDoNotInvalidateTheSourceSnapshot(): void
    {
        $id = $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $this->connection->executeStatement('CREATE TABLE unrelated (value TEXT)');
        $http = new MockHttpClient(function () use ($id): MockResponse {
            $this->connection->insert('unrelated', ['value' => 'changed']);
            $this->connection->update('om_observation', ['content' => 'omega'], ['observation_id' => $id]);
            $this->connection->update('om_observation', ['content' => 'alpha'], ['observation_id' => $id]);

            return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
        });
        $result = $this->query($http)->search('alpha');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['partial']);
        $this->assertSame('alpha', $result['results'][0]['content']);
    }

    #[Test]
    public function canonicalMetadataChangesDoNotReembedUnchangedChunks(): void
    {
        $id = $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $this->connection->update('om_observation', ['run_id' => 'updated-session', 'timestamp' => '2026-09-13 12:00', 'relevance' => 'low'], ['observation_id' => $id]);
        $result = $this->query()->search('alpha', after: '2026-09-13');
        $this->assertTrue($result['partial']);
        $this->assertSame('updated-session', $result['results'][0]['session_id']);
        $this->assertSame('low', $result['results'][0]['importance']);
        $requests = \count($this->requests);
        $this->assertTrue($this->index()->synchronize());
        $this->assertCount($requests, $this->requests);
    }

    #[Test]
    public function changedSourceDuringIndexEmbeddingCannotPublishACompleteSnapshot(): void
    {
        $id = $this->observation('alpha');
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        $http = new MockHttpClient(function () use ($id): MockResponse {
            $this->connection->update('om_observation', ['content' => 'omega'], ['observation_id' => $id]);

            return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
        });
        $index = new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $http));
        $this->assertFalse($index->synchronize());
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT complete FROM om_semantic_state'));
        $partial = $this->query()->search('omega');
        $this->assertTrue($partial['partial']);
        $this->assertSame([], $partial['results']);
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame('omega', $this->query()->search('omega')['results'][0]['content']);
    }

    #[Test]
    public function dimensionChangesDirtyTheIndexForBothQueryAndContinuation(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        $http = new MockHttpClient(new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0,0.5]}]}'));
        $result = $this->query($http)->search('alpha');
        $this->assertSame('embedding_dimensions_changed', $result['error']);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT dirty FROM om_semantic_state'));
        $this->assertTrue($this->index()->synchronize());
        $this->observation('omega');
        $http = new MockHttpClient(new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0,0.5]}]}'));
        $index = new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $http));
        try {
            $index->synchronize();
            $this->fail('Changed embedding dimensions must not be published.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Embedding dimensions changed; semantic index requires rebuilding.', $error->getMessage());
        }
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT dirty FROM om_semantic_state'));
        $this->assertTrue($this->index()->synchronize());
        $this->assertFalse($this->query()->search('alpha')['partial']);
    }

    #[Test]
    public function unchangedSearchDoesNotRequireTheCanonicalWriterLock(): void
    {
        $this->observation('alpha');
        $this->assertTrue($this->index()->synchronize());
        $other = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($this->path);
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('UPDATE om_observation SET token_count = token_count + 1');
            $index = new SemanticIndexService($other, $this->path, $semantic, new SemanticApiClient($semantic, $this->http));
            $result = $index->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
            $this->assertFalse($result['partial']);
            $this->assertSame('alpha', $result['results'][0]['content']);
        } finally {
            $this->connection->rollBack();
            $other->close();
        }
    }

    #[Test]
    public function unchangedChunksOfAChangedSourceReturnItsCurrentCanonicalContent(): void
    {
        $original = str_repeat('alpha ', 400).'old tail';
        $updated = str_repeat('alpha ', 400).'new tail';
        $id = $this->observation($original);
        $this->assertTrue($this->index()->synchronize());
        $this->connection->update('om_observation', ['content' => $updated], ['observation_id' => $id]);
        $result = $this->query()->search('alpha');
        $this->assertTrue($result['partial']);
        $this->assertSame(1, $result['count']);
        $this->assertSame($updated, $result['results'][0]['content']);
        $this->assertSame($id, $result['results'][0]['id']);
    }

    /** @return iterable<string, array{bool}> */
    public static function refreshedSourceCases(): iterable
    {
        yield 'new source' => [true];
        yield 'changed source' => [false];
    }

    #[Test]
    #[DataProvider('refreshedSourceCases')]
    public function sourceRefreshRetriesTheWholeSnapshotAfterAConcurrentCommit(bool $newSource): void
    {
        $id = $this->observation('alpha original');
        $this->assertTrue($this->index()->synchronize());
        if ($newSource) {
            $id = $this->observation('alpha new');
        } else {
            $this->connection->update('om_observation', ['content' => 'alpha changed'], ['observation_id' => $id]);
        }
        $other = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($this->path);
        $pdo = $this->connection->getNativeConnection();
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [Support\SourceRefreshStatement::class]);
        Support\SourceRefreshStatement::$sourceReads = 0;
        Support\SourceRefreshStatement::$conflictCodes = [];
        Support\SourceRefreshStatement::$afterSourceRead = static function () use ($other, $id): void {
            Support\SourceRefreshStatement::$afterSourceRead = null;
            $other->update('om_observation', ['content' => 'omega committed'], ['observation_id' => $id]);
        };
        $logger = new TestLogger();
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        try {
            $index = new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $this->http), $logger, 'snapshot-retry-run');
            $this->assertTrue($index->synchronize());
            $this->assertSame([517], Support\SourceRefreshStatement::$conflictCodes);
            $this->assertGreaterThanOrEqual(2, Support\SourceRefreshStatement::$sourceReads);
            $this->assertSame(0, $this->connection->getTransactionNestingLevel());
            $this->assertSame(5000, (int) $this->connection->fetchOne('PRAGMA busy_timeout'));
            $this->assertSame('omega committed', $this->connection->fetchOne("SELECT json_extract(metadata, '$.source.content') FROM om_semantic_document WHERE id = ?", ['source:observation:'.$id]));
            $conflicts = array_values(array_filter($logger->records, static fn (array $record): bool => 'om.semantic.source_refresh_conflict' === $record['message']));
            $this->assertCount(1, $conflicts);
            $this->assertSame(['run_id' => 'snapshot-retry-run', 'session_id' => 'snapshot-retry-run', 'component' => 'observational_memory', 'event_type' => 'om.semantic.source_refresh_conflict', 'sqlite_code' => 517, 'attempt' => 1, 'retrying' => true], $conflicts[0]['context']);
            $result = $this->query()->search('omega');
            $this->assertFalse($result['partial']);
            $contents = array_column($result['results'], 'content', 'id');
            $this->assertSame('omega committed', $contents[$id]);
        } finally {
            Support\SourceRefreshStatement::$afterSourceRead = null;
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous);
            $other->close();
        }
    }

    #[Test]
    public function exhaustedSnapshotConflictsAndUnrelatedFailuresPropagateWithoutPublishingManifests(): void
    {
        $id = $this->observation('alpha original');
        $this->assertTrue($this->index()->synchronize());
        $this->connection->update('om_observation', ['content' => 'alpha changed'], ['observation_id' => $id]);
        $other = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($this->path);
        $pdo = $this->connection->getNativeConnection();
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [Support\SourceRefreshStatement::class]);
        Support\SourceRefreshStatement::$sourceReads = 0;
        Support\SourceRefreshStatement::$conflictCodes = [];
        Support\SourceRefreshStatement::$afterSourceRead = static function () use ($other, $id): void {
            $other->update('om_observation', ['content' => 'alpha commit '.Support\SourceRefreshStatement::$sourceReads], ['observation_id' => $id]);
        };
        $logger = new TestLogger();
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);
        $index = new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $this->http), $logger);
        try {
            try {
                $index->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
                $this->fail('Exhausted snapshot conflicts must propagate.');
            } catch (DriverException $error) {
                $this->assertSame(517, $error->getCode());
            }
            $this->assertSame([517, 517, 517], Support\SourceRefreshStatement::$conflictCodes);
            $this->assertSame(3, Support\SourceRefreshStatement::$sourceReads);
            $this->assertSame(0, $this->connection->getTransactionNestingLevel());
            $this->assertSame('alpha original', $this->connection->fetchOne("SELECT json_extract(metadata, '$.source.content') FROM om_semantic_document WHERE id = ?", ['source:observation:'.$id]));
            $this->assertSame([true, true, false], array_column(array_column($logger->records, 'context'), 'retrying'));
            $this->assertCount(3, $logger->records);
            Support\SourceRefreshStatement::$afterSourceRead = null;
            Support\SourceRefreshStatement::$sourceReads = 0;
            $this->connection->executeStatement('PRAGMA busy_timeout = 0');
            $other->beginTransaction();
            $other->executeStatement('UPDATE om_observation SET token_count = token_count + 1');
            try {
                $index->search('alpha', ['observation' => [null, null], 'reflection' => [null, null]], static function (): void {});
                $this->fail('Unrelated database errors must propagate without retry.');
            } catch (DriverException $error) {
                $this->assertSame(5, $error->getCode());
            } finally {
                $other->rollBack();
            }
            $this->assertSame(1, Support\SourceRefreshStatement::$sourceReads);
            $this->assertSame([517, 517, 517, 5], Support\SourceRefreshStatement::$conflictCodes);
            $this->assertCount(3, $logger->records);
            $this->assertSame(0, $this->connection->getTransactionNestingLevel());
            $this->assertSame('alpha original', $this->connection->fetchOne("SELECT json_extract(metadata, '$.source.content') FROM om_semantic_document WHERE id = ?", ['source:observation:'.$id]));
        } finally {
            Support\SourceRefreshStatement::$afterSourceRead = null;
            $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous);
            $other->close();
        }
    }

    private function settings(string $model = 'coderankembed'): OmSettings
    {
        return OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => ['embedding_api' => [
            'base_url' => 'http://embeddings.test/v1', 'model_id' => $model,
            'query_prefix' => 'Represent this query for searching relevant code:',
        ]]]);
    }

    private function index(): SemanticIndexService
    {
        $semantic = $this->settings->semantic;
        $this->assertNotNull($semantic);

        return new SemanticIndexService($this->connection, $this->path, $semantic, new SemanticApiClient($semantic, $this->http));
    }

    private function query(?MockHttpClient $http = null, ?TestLogger $logger = null): OmQueryService
    {
        $api = $this->createStub(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn($this->directory);

        return new OmQueryService($api, $this->settings, $logger ?? new TestLogger(), $http ?? $this->http);
    }

    private function observation(string $content, string $session = '32', string $timestamp = '2026-09-12 12:00'): string
    {
        $id = hash('sha256', $content);
        $this->connection->insert('om_observation', [
            'observation_id' => $id, 'run_id' => $session, 'boundary_key' => $id,
            'source_start_seq' => 1, 'source_end_seq' => 1, 'source_refs_json' => '[]',
            'content' => $content, 'content_hash' => $id, 'relevance' => 'high',
            'timestamp' => $timestamp, 'token_count' => 10, 'observer_model' => 'test/model',
            'observer_schema_version' => '1', 'created_at' => '2026-09-12T12:00:00+00:00',
        ]);

        return $id;
    }
}
