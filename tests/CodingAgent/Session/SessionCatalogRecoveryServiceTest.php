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
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
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

        // Enrich fixture with model/reasoning/model_changed for metadata recovery.
        $this->appendRunStartedMetadataAndModelChange($eventsPath, $sessionId, $originalBytes);
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

        // Existing SessionInitializer replay remains usable after catalog recovery.
        /** @var SessionInitializer $initializer */
        $initializer = self::getContainer()->get(SessionInitializer::class);
        $state = $initializer->initialize($sessionId);
        $this->assertTrue($state->resuming);
        $this->assertSame($sessionId, $state->sessionId);
        $blocks = $initializer->buildInitialTranscript($state);
        $this->assertNotEmpty($blocks);
        $joined = '';
        foreach ($blocks as $block) {
            $joined .= ($block->text ?? '').' ';
        }
        $this->assertStringContainsString('Here is the answer you requested.', $joined);

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

        // Non-canonical / malformed neighbors must not block recovery.
        $malformedDir = $projectDir.'/.hatfield/sessions/88';
        mkdir($malformedDir, 0777, true);
        file_put_contents($malformedDir.'/events.jsonl', "{not-json\n");

        $nonNumeric = $projectDir.'/.hatfield/sessions/not-a-number';
        mkdir($nonNumeric, 0777, true);
        file_put_contents($nonNumeric.'/events.jsonl', "{\"run_id\":\"x\"}\n");

        $zeroDir = $projectDir.'/.hatfield/sessions/0';
        mkdir($zeroDir, 0777, true);
        file_put_contents($zeroDir.'/events.jsonl', "{}\n");

        ($this->recovery)();
        $this->em->clear();
        $this->assertNotNull($this->sessionStore->findSession($validId));
        $this->assertNull($this->sessionStore->findSession('88'));
        $this->assertNull($this->sessionStore->findSession('0'));

        // Second pass is a no-op for existing rows (INSERT OR IGNORE / exists short-circuit).
        ($this->recovery)();
        $this->em->clear();
        $again = $this->sessionStore->findSession($validId);
        $this->assertNotNull($again);
        $this->assertSame($validBytes, file_get_contents($validEventsPath));

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

    /**
     * @param non-empty-string $eventsPath
     * @param non-empty-string $sessionId
     * @param non-empty-string $originalBytes
     */
    private function appendRunStartedMetadataAndModelChange(string $eventsPath, string $sessionId, string $originalBytes): void
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
            'model' => 'openai/old-model',
            'reasoning' => 'medium',
        ];
        $payload['payload'] = $inner;
        $first['payload'] = $payload;
        $lines[0] = json_encode($first, \JSON_THROW_ON_ERROR);

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $lines[] = json_encode([
            'schema_version' => '1.0',
            'run_id' => $sessionId,
            'seq' => 100,
            'turn_no' => 3,
            'type' => 'model_changed',
            'payload' => [
                'model' => 'deepseek/deepseek-v4-flash',
                'previous_model' => 'openai/old-model',
            ],
            'ts' => $now,
        ], \JSON_THROW_ON_ERROR);

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
        }
    }
}
