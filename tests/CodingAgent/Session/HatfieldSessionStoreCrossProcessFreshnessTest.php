<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cross-process freshness of session metadata reads and writes.
 *
 * The TUI and runtime worker share one SQLite database through separate
 * processes. Within one long-lived EntityManager, Doctrine's identity map can
 * hide committed changes from another process. These cases keep the container
 * EntityManager and simulate the foreign writer with DBAL UPDATE statements
 * so the identity map stays intact.
 */
final class HatfieldSessionStoreCrossProcessFreshnessTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $store;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->store = $store;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    public function findSessionSeesModelCommittedByExternalWriter(): void
    {
        $sessionId = $this->store->createSession('/task-start example');
        $this->assertNotNull($this->store->findSession($sessionId));

        $this->writeSessionModelOutsideOrm((int) $sessionId, 'runpod/Qwen3.8-27B', 'runpod', 'Qwen3.8-27B');

        $session = $this->store->findSession($sessionId);

        $this->assertNotNull($session);
        $this->assertSame('runpod/Qwen3.8-27B', $session->model);
        $this->assertSame('runpod', $session->modelProvider);
        $this->assertSame('Qwen3.8-27B', $session->modelName);
    }

    #[Test]
    public function listSessionsSeesModelCommittedByExternalWriter(): void
    {
        $sessionId = $this->store->createSession('/task-start example');
        $this->assertNotNull($this->store->findSession($sessionId));

        $this->writeSessionModelOutsideOrm((int) $sessionId, 'openai-codex/gpt-6-astra', 'openai-codex', 'gpt-6-astra');

        $sessions = $this->store->listSessions();
        $match = null;
        foreach ($sessions as $session) {
            if ($session['sessionId'] === $sessionId) {
                $match = $session;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertSame('openai-codex/gpt-6-astra', $match['model']);
        $this->assertSame('openai-codex', $match['model_provider']);
        $this->assertSame('gpt-6-astra', $match['model_name']);
    }

    #[Test]
    public function updateMetadataWritesWhenCachedModelMatchesRequestedValueButDatabaseDiffers(): void
    {
        $sessionId = $this->store->createSession('/task-start example');
        $this->store->updateMetadata($sessionId, [
            'model' => 'provider/model-a',
            'model_provider' => 'provider',
            'model_name' => 'model-a',
        ]);

        $cached = $this->store->findSession($sessionId);
        $this->assertNotNull($cached);
        $this->assertSame('provider/model-a', $cached->model);

        $this->writeSessionModelOutsideOrm((int) $sessionId, 'provider/model-b', 'provider', 'model-b');

        // User re-selects the still-cached value A while the database holds B.
        $this->store->updateMetadata($sessionId, [
            'model' => 'provider/model-a',
            'model_provider' => 'provider',
            'model_name' => 'model-a',
        ]);

        $row = $this->connection->fetchAssociative(
            'SELECT model, model_provider, model_name FROM hatfield_session WHERE id = :id',
            ['id' => (int) $sessionId],
        );
        $this->assertIsArray($row);
        $this->assertSame('provider/model-a', $row['model']);
        $this->assertSame('provider', $row['model_provider']);
        $this->assertSame('model-a', $row['model_name']);

        $session = $this->store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('provider/model-a', $session->model);
    }

    #[Test]
    public function claimReasoningBaselineSeesExternallyChangedBaseline(): void
    {
        $sessionId = $this->store->createSession('/task-start example');
        $this->assertNull($this->store->claimReasoningBaseline($sessionId, 'provider/model-a', 'high'));

        $this->connection->executeStatement(
            'UPDATE hatfield_session SET reasoning_baseline = :baseline WHERE id = :id',
            [
                'baseline' => json_encode(['model' => 'provider/model-a', 'effort' => 'low'], \JSON_THROW_ON_ERROR),
                'id' => (int) $sessionId,
            ],
        );

        $this->assertSame(
            [
                'baseline' => 'low',
                'update' => 'high',
                'last_emitted' => 'low',
            ],
            $this->store->claimReasoningBaseline($sessionId, 'provider/model-a', 'high'),
        );
    }

    #[Test]
    public function existsReportsDeletionVisibleOnlyInDatabase(): void
    {
        $sessionId = $this->store->createSession('/task-start example');
        $entity = $this->entityManager->find(HatfieldSession::class, (int) $sessionId);
        $this->assertNotNull($entity);
        $this->assertTrue($this->store->exists($sessionId));

        $this->connection->executeStatement(
            'DELETE FROM hatfield_session WHERE id = :id',
            ['id' => (int) $sessionId],
        );

        $this->assertFalse($this->store->exists($sessionId));
        $this->assertTrue($this->entityManager->contains($entity));
    }

    /**
     * Simulate another process committing session model fields without touching
     * this EntityManager's identity map.
     */
    private function writeSessionModelOutsideOrm(
        int $sessionId,
        string $model,
        string $modelProvider,
        string $modelName,
    ): void {
        $updated = $this->connection->executeStatement(
            'UPDATE hatfield_session
             SET model = :model,
                 model_provider = :model_provider,
                 model_name = :model_name,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'model' => $model,
                'model_provider' => $modelProvider,
                'model_name' => $modelName,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $sessionId,
            ],
        );

        $this->assertSame(1, $updated);
    }
}
