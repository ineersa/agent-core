<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticApiClient;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexService;
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
    public function backfillResumesInBoundedBatchesAndRejectsPartialOrStaleSearch(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->observation('memory '.$i);
        }
        $this->assertSame('index_building', $this->query()->search('memory')['error']);
        $this->assertFalse($this->index()->synchronize());
        $this->assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_semantic_document'));
        $progress = $this->query()->search('memory');
        $this->assertSame('index_building', $progress['error']);
        $this->assertSame(4, $progress['indexed_count']);
        $this->assertSame(6, $progress['source_count']);
        // New service instances model successive worker jobs, not a cached cursor.
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame([4, 2], array_map(static fn (array $r): int => \count($r['input']), $this->requests));
        $this->assertTrue($this->index()->synchronize());
        $this->assertCount(2, $this->requests);
        $this->observation('late memory');
        $this->assertSame('index_building', $this->query()->search('memory')['error']);
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame(7, $this->query()->search('memory')['count']);
    }

    #[Test]
    public function interruptedCrossStoreWriteRebuildsWithoutDuplicateIds(): void
    {
        $id = $this->observation('alpha');
        // Fail the real text-store write AFTER Vektor has accepted the document.
        $this->connection->executeStatement('CREATE TABLE om_semantic_document (id TEXT PRIMARY KEY, vector TEXT NOT NULL, metadata TEXT)');
        $this->connection->executeStatement("CREATE TRIGGER fail_index BEFORE INSERT ON om_semantic_document BEGIN SELECT RAISE(ABORT, 'injected storage failure'); END");
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
        $this->assertSame('index_building', $this->query()->search('alpha')['error']);
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame([$kept], array_column($this->query()->search('memory')['results'], 'id'));
        $this->settings = $this->settings('replacement-embedding-model');
        $this->assertSame('index_building', $this->query()->search('memory')['error']);
        $this->assertTrue($this->index()->synchronize());
        $this->assertSame('replacement-embedding-model', $this->requests[array_key_last($this->requests)]['model']);
        $this->assertSame([$kept], array_column($this->query()->search('memory')['results'], 'id'));
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
    public function rejectingCappedFusedCandidatesStillReportsTruncation(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $this->observation('alpha memory '.$i);
        }
        $complete = false;
        for ($i = 0; $i < 26 && !$complete; ++$i) {
            $complete = $this->index()->synchronize();
        }
        $this->assertTrue($complete);
        $this->settings = OmSettings::fromArray(['storage' => ['database' => $this->path], 'semantic' => [
            'embedding_api' => ['base_url' => 'http://embeddings.test/v1', 'model_id' => 'coderankembed'],
            'reranker_api' => ['base_url' => 'http://reranker.test/v1', 'model_id' => 'rank', 'min_score' => 1],
        ]]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/embeddings')) {
                return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.0]}]}');
            }
            $documents = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['documents'];
            $scores = [];
            foreach ($documents as $i => $_) {
                $scores[] = ['index' => $i, 'relevance_score' => 0];
            }

            return new MockResponse(json_encode(['results' => $scores], \JSON_THROW_ON_ERROR));
        });
        $result = $this->query($http)->search('alpha');
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['count']);
        $this->assertTrue($result['truncated']);
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
