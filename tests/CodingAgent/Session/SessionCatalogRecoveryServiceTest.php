<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionCatalogRecoveryService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Kernel/DB proof: orphan session dirs with events.jsonl reappear in the catalog
 * after state row loss, without rewriting event bytes.
 */
final class SessionCatalogRecoveryServiceTest extends IsolatedKernelTestCase
{
    private EntityManagerInterface $em;
    private HatfieldSessionStore $sessionStore;
    private SessionCatalogRecoveryService $recovery;
    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->sessionStore = $store;

        $this->logger = new TestLogger();

        /** @var AppConfig $appConfig */
        $appConfig = self::getContainer()->get(AppConfig::class);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var SessionRunEventStore $eventStore */
        $eventStore = self::getContainer()->get(SessionRunEventStore::class);

        // Construct with TestLogger so privacy assertions see recovery diagnostics.
        // Production wiring is StartupDatabaseMigrator → SessionCatalogRecoveryService.
        $this->recovery = new SessionCatalogRecoveryService(
            $appConfig,
            $this->sessionStore,
            $connection,
            $eventStore,
            $this->logger,
        );
    }

    public function testRecoversOrphanCatalogRowAndPreservesEventBytes(): void
    {
        $sessionId = '42';
        $projectDir = $this->isolatedCwd();
        ResumeCanonicalEventsFixture::write($projectDir, $sessionId);
        $eventsPath = $projectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
        $this->assertFileExists($eventsPath);
        $originalBytes = file_get_contents($eventsPath);
        $this->assertNotFalse($originalBytes);
        $this->assertStringContainsString('Tell me about testing.', $originalBytes);

        // Enrich fixture with authoritative run-start metadata for recovery.
        $this->appendRunStartedMetadata($eventsPath, $sessionId, $originalBytes);
        $originalBytes = file_get_contents($eventsPath);
        $this->assertNotFalse($originalBytes);

        $this->assertNull($this->sessionStore->findSession($sessionId));

        ($this->recovery)();
        $this->em->clear();

        $session = $this->sessionStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame(42, $session->id);
        $this->assertSame('Tell me about testing.', $session->prompt);
        $this->assertSame('Tell me about testing.', $session->name);
        $this->assertSame('deepseek/deepseek-v4-flash', $session->model);
        $this->assertSame('deepseek', $session->modelProvider);
        $this->assertSame('deepseek-v4-flash', $session->modelName);
        $this->assertSame('medium', $session->reasoning);
        $this->assertTrue(Uuid::isValid((string) $session->providerCacheKey));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString((string) $session->providerCacheKey));

        $catalog = $this->sessionStore->listSessions();
        $ids = array_column($catalog, 'sessionId');
        $this->assertContains($sessionId, $ids);
        $row = null;
        foreach ($catalog as $entry) {
            if ($entry['sessionId'] === $sessionId) {
                $row = $entry;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame('Tell me about testing.', $row['name']);
        $this->assertSame('deepseek/deepseek-v4-flash', $row['model']);

        $this->assertSame($originalBytes, file_get_contents($eventsPath), 'recovery must not rewrite events.jsonl');

        // Catalog recovery admits resume, not a second synchronous archive replay.
        /** @var SessionInitializer $initializer */
        $initializer = self::getContainer()->get(SessionInitializer::class);
        $state = $initializer->initialize($sessionId);
        $this->assertTrue($state->resuming);
        $this->assertSame($sessionId, $state->sessionId);
        $blocks = $initializer->buildInitialTranscript($state);
        $this->assertFalse($state->sessionReady);
        $this->assertCount(1, $blocks);
        $this->assertSame('Restoring session...', $blocks[0]->text);

        // Subsequent create allocates a different id and leaves recovered bytes alone.
        $newId = $this->sessionStore->createSession('fresh after recovery');
        $this->assertNotSame($sessionId, $newId);
        $this->assertGreaterThan(42, (int) $newId);
        $this->assertSame($originalBytes, file_get_contents($eventsPath));
    }

    public function testRecoveryIsIdempotentAndSkipsMalformedWithoutBlockingValid(): void
    {
        $validId = '77';
        $projectDir = $this->isolatedCwd();
        ResumeCanonicalEventsFixture::write($projectDir, $validId);
        $validEventsPath = $projectDir.'/.hatfield/sessions/'.$validId.'/events.jsonl';
        $validBytes = file_get_contents($validEventsPath);
        $this->assertNotFalse($validBytes);

        // Non-canonical / malformed neighbors must not block recovery or steal IDs.
        $malformedDir = $projectDir.'/.hatfield/sessions/88';
        mkdir($malformedDir, 0777, true);
        file_put_contents($malformedDir.'/events.jsonl',
            $this->minimalRunStartedJsonl('88', 'private orphan prompt')."{not-json\n",
        );

        $nonNumeric = $projectDir.'/.hatfield/sessions/not-a-number';
        mkdir($nonNumeric, 0777, true);
        file_put_contents($nonNumeric.'/events.jsonl', "{\"run_id\":\"x\"}\n");

        $zeroDir = $projectDir.'/.hatfield/sessions/0';
        mkdir($zeroDir, 0777, true);
        file_put_contents($zeroDir.'/events.jsonl', "{}\n");

        // Leading-zero alias must not recover as id 7 or poison allocation.
        $leadingZeroDir = $projectDir.'/.hatfield/sessions/007';
        mkdir($leadingZeroDir, 0777, true);
        file_put_contents(
            $leadingZeroDir.'/events.jsonl',
            $this->minimalRunStartedJsonl('007', 'leading-zero must not become id 7'),
        );

        // Overflowing decimal saturates under (int) but must not recover as PHP_INT_MAX.
        $overflowName = (string) \PHP_INT_MAX.'0';
        $overflowDir = $projectDir.'/.hatfield/sessions/'.$overflowName;
        mkdir($overflowDir, 0777, true);
        file_put_contents(
            $overflowDir.'/events.jsonl',
            $this->minimalRunStartedJsonl($overflowName, 'overflow must not recover'),
        );

        $maxBefore = (int) ($this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM hatfield_session') ?: 0);

        ($this->recovery)();
        $this->em->clear();
        $this->assertNotNull($this->sessionStore->findSession($validId));
        $this->assertNull($this->sessionStore->findSession('88'));
        $this->assertNull($this->sessionStore->findSession('0'));
        $this->assertNull($this->sessionStore->findSession('007'));
        $this->assertNull($this->sessionStore->findSession('7'), 'leading-zero alias must not insert id 7');
        $this->assertNull($this->sessionStore->findSession($overflowName));
        $this->assertNull(
            $this->sessionStore->findSession((string) \PHP_INT_MAX),
            'overflow alias must not insert PHP_INT_MAX',
        );

        $maxAfter = (int) ($this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM hatfield_session') ?: 0);
        $this->assertSame(77, $maxAfter);
        $this->assertLessThanOrEqual(77, $maxAfter);
        $this->assertGreaterThanOrEqual($maxBefore, $maxAfter);

        // Second pass is a no-op for existing rows (ON CONFLICT DO NOTHING / exists short-circuit).
        ($this->recovery)();
        $this->em->clear();
        $again = $this->sessionStore->findSession($validId);
        $this->assertNotNull($again);
        $this->assertSame($validBytes, file_get_contents($validEventsPath));

        // New create must not collide with ignored overflow/leading-zero paths.
        $newId = $this->sessionStore->createSession('after noncanonical neighbors');
        $this->assertGreaterThan(77, (int) $newId);
        $this->assertNotSame('007', $newId);
        $this->assertNotSame($overflowName, $newId);
        $this->assertFileExists($leadingZeroDir.'/events.jsonl');
        $this->assertFileExists($overflowDir.'/events.jsonl');

        $this->assertLogsArePrivacySafe();
    }

    public function testIncompatibleSchemaCannotSupplyCatalogMetadata(): void
    {
        $directory = $this->isolatedCwd().'/.hatfield/sessions/56';
        mkdir($directory, 0777, true);
        $start = json_decode($this->minimalRunStartedJsonl('56', 'current prompt'), true, 512, \JSON_THROW_ON_ERROR);
        $start['ts'] = '2026-01-02T00:00:00+00:00';
        $incompatible = $start;
        $incompatible['schema_version'] = '999.0';
        $incompatible['seq'] = 9;
        $incompatible['ts'] = '2030-01-01T00:00:00+00:00';
        $incompatible['payload']['payload']['messages'][0]['content'][0]['text'] = 'incompatible private prompt';
        $path = $directory.'/events.jsonl';
        $bytes = json_encode($start, \JSON_THROW_ON_ERROR)."\n".json_encode($incompatible, \JSON_THROW_ON_ERROR)."\n";
        file_put_contents($path, $bytes);

        ($this->recovery)();
        $this->em->clear();
        $session = $this->sessionStore->findSession('56');
        $this->assertNotNull($session);
        $this->assertSame('current prompt', $session->prompt);
        $this->assertSame('2026-01-02 00:00:00', $session->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02 00:00:00', $session->updatedAt->format('Y-m-d H:i:s'));
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertLogsArePrivacySafe();
    }

    public function testCreateSessionRefusesPreexistingDirectoryCollision(): void
    {
        $connection = $this->em->getConnection();
        $max = (int) ($connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM hatfield_session') ?: 0);
        $collisionId = $max + 1;

        // Force the next AUTOINCREMENT to the collision id.
        $seqExists = $connection->fetchOne(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'",
        );
        if (false === $seqExists || null === $seqExists) {
            $this->markTestSkipped('sqlite_sequence unavailable');
        }

        $hasSeq = $connection->fetchOne(
            "SELECT 1 FROM sqlite_sequence WHERE name = 'hatfield_session'",
        );
        if (false === $hasSeq || null === $hasSeq) {
            $connection->executeStatement(
                "INSERT INTO sqlite_sequence (name, seq) VALUES ('hatfield_session', ?)",
                [$max],
            );
        } else {
            $connection->executeStatement(
                "UPDATE sqlite_sequence SET seq = ? WHERE name = 'hatfield_session'",
                [$max],
            );
        }

        $orphanDir = $this->sessionStore->resolveSessionsBasePath().'/'.$collisionId;
        mkdir($orphanDir, 0777, true);
        $marker = "orphan-bytes-must-survive\n";
        file_put_contents($orphanDir.'/events.jsonl', $marker);

        try {
            $this->sessionStore->createSession('should not wipe orphan');
            $this->fail('createSession must refuse preexisting session directory');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to create session', $e->getMessage());
        }

        $this->assertSame($marker, file_get_contents($orphanDir.'/events.jsonl'));
        $this->assertNull($this->sessionStore->findSession((string) $collisionId));
    }

    public function testRecoversParentRunIdFromChildRunStartedMetadata(): void
    {
        $sessionId = '55';
        $dir = $this->isolatedCwd().'/.hatfield/sessions/'.$sessionId;
        mkdir($dir, 0777, true);
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $event = [
            'schema_version' => '1.0',
            'run_id' => $sessionId,
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [['type' => 'text', 'text' => 'child task']],
                        ],
                    ],
                    'metadata' => [
                        'model' => 'openai/gpt-test',
                        'reasoning' => 'low',
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => '12',
                            'agent_name' => 'scout',
                            'artifact_id' => 'art-1',
                        ],
                    ],
                ],
            ],
            'ts' => $now,
        ];
        file_put_contents($dir.'/events.jsonl', json_encode($event, \JSON_THROW_ON_ERROR)."\n");

        ($this->recovery)();
        $this->em->clear();

        $session = $this->sessionStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('12', $session->parentId);
        $this->assertSame('child task', $session->prompt);
        $this->assertSame('openai/gpt-test', $session->model);
        $this->assertSame('low', $session->reasoning);
    }

    public function testColdCatalogMemoryPlateausInIndependent128MProcesses(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('catalog-recovery-memory');
        try {
            $measurements = [];
            foreach ([1000, 16000] as $count) {
                $process = new Process([\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/catalog-recovery-memory.php', $directory, (string) $count], \dirname(__DIR__, 3), ['HATFIELD_SESSION_ID' => false], timeout: 8);
                $process->mustRun();
                $measurements[] = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            }
            [$small, $large] = $measurements;
            $this->assertGreaterThan($small['archive_bytes'] * 15, $large['archive_bytes']);
            $this->assertLessThan(128 * 1024 * 1024, $large['peak_bytes']);
            $this->assertLessThanOrEqual($small['peak_bytes'] + 4 * 1024 * 1024, $large['peak_bytes']);
            foreach ($measurements as $measurement) {
                $this->assertSame($measurement['source_hash'], $measurement['recovered_hash']);
                $this->assertSame($measurement['archive_bytes'], $measurement['recovered_bytes']);
                $this->assertSame('Fixed catalog prompt', $measurement['prompt']);
                $this->assertSame('Fixed catalog prompt', $measurement['name']);
                $this->assertSame('openai/gpt-test', $measurement['model']);
                $this->assertSame('medium', $measurement['reasoning']);
                $this->assertSame('12', $measurement['parent_id']);
                $this->assertSame('2026-01-01 00:00:00', $measurement['created_at']);
                $this->assertSame('2026-01-03 00:00:00', $measurement['updated_at']);
                $this->assertTrue($measurement['fresh_orphan']);
                $this->assertTrue($measurement['provider_uuid_v7']);
                $this->assertSame($measurement['provider_cache_key'], $measurement['repeated_provider_cache_key']);
            }
            fwrite(\STDERR, 'catalog-recovery memory: '.json_encode($measurements, \JSON_THROW_ON_ERROR)."\n");
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function minimalRunStartedJsonl(string $runId, string $prompt): string
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $event = [
            'schema_version' => '1.0',
            'run_id' => $runId,
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [['type' => 'text', 'text' => $prompt]],
                        ],
                    ],
                    'metadata' => [
                        'model' => 'openai/gpt-test',
                        'reasoning' => 'low',
                    ],
                ],
            ],
            'ts' => $now,
        ];

        return json_encode($event, \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param non-empty-string $eventsPath
     * @param non-empty-string $sessionId
     * @param non-empty-string $originalBytes
     */
    private function appendRunStartedMetadata(string $eventsPath, string $sessionId, string $originalBytes): void
    {
        $lines = array_values(array_filter(explode("\n", trim($originalBytes)), static fn (string $l): bool => '' !== $l));
        $this->assertNotEmpty($lines);
        $first = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($first);
        $payload = $first['payload'] ?? [];
        $this->assertIsArray($payload);
        $inner = $payload['payload'] ?? [];
        $this->assertIsArray($inner);
        $inner['metadata'] = [
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ];
        $payload['payload'] = $inner;
        $first['payload'] = $payload;
        $lines[0] = json_encode($first, \JSON_THROW_ON_ERROR);

        file_put_contents($eventsPath, implode("\n", $lines)."\n");
    }

    private function assertLogsArePrivacySafe(): void
    {
        foreach ($this->logger->records as $record) {
            $encoded = json_encode($record, \JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Tell me about testing.', $encoded);
            $this->assertStringNotContainsString('FILE CONTENTS HERE', $encoded);
            $this->assertStringNotContainsString('{not-json', $encoded);
            $this->assertStringNotContainsString('child task', $encoded);
            $this->assertStringNotContainsString('private orphan prompt', $encoded);
            $this->assertStringNotContainsString('incompatible private prompt', $encoded);
        }
    }
}
