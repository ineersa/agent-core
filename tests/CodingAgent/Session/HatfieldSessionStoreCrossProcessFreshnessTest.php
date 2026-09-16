<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cross-process freshness of session metadata reads.
 *
 * The TUI and the runtime worker are separate processes, each with its own
 * EntityManager over the same SQLite database. Doctrine's identity map makes
 * em->find() return a stale in-process snapshot once the entity is cached,
 * which historically (a) hid the worker-persisted model from the TUI's
 * tier-2 model resolution and Ctrl+P baseline, and (b) hid TUI-committed
 * mid-run model changes from the worker's per-turn resolution.
 *
 * These tests reproduce that two-EntityManager reality and pin the refresh
 * behavior that makes hatfield_session the per-turn source of truth.
 *
 * Deliberate deviation from the IsolatedKernelTestCase rule (tests/AGENTS.md):
 * the kernel container exposes a single EntityManager and DAMA wraps it in
 * per-method transactions — reproducing two-process identity-map staleness
 * requires two independent EMs hand-built over one committed SQLite file.
 * Do not copy this pattern for ordinary DB tests.
 */
final class HatfieldSessionStoreCrossProcessFreshnessTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;
    private EntityManager $tuiEm;
    private EntityManager $workerEm;
    private HatfieldSessionStore $tuiStore;
    private HatfieldSessionStore $workerStore;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('session-cross-process');
        $this->dbPath = $this->tempDir.'/state.sqlite';

        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../../src'], true);
        $config->enableNativeLazyObjects(true);
        $params = ['driver' => 'pdo_sqlite', 'path' => $this->dbPath];

        $this->tuiEm = new EntityManager(DriverManager::getConnection($params, $config), $config);
        (new SchemaTool($this->tuiEm))->createSchema([$this->tuiEm->getClassMetadata(HatfieldSession::class)]);
        $this->workerEm = new EntityManager(DriverManager::getConnection($params, $config), $config);

        $this->tuiStore = $this->makeStore($this->tuiEm);
        $this->workerStore = $this->makeStore($this->workerEm);
    }

    protected function tearDown(): void
    {
        $this->workerEm->getConnection()->close();
        $this->tuiEm->getConnection()->close();
        TestDirectoryIsolation::removeDirectory($this->tempDir);
    }

    #[Test]
    public function tuiSeesModelPersistedByWorkerProcess(): void
    {
        // Draft promotion in the TUI process creates the row (model NULL).
        $sessionId = $this->tuiStore->createSession('/task-start example');
        // The runtime worker (separate EM/process) persists the effective model at start().
        $this->workerStore->updateMetadata($sessionId, [
            'model' => 'runpod/Qwen3.8-27B',
            'model_provider' => 'runpod',
            'model_name' => 'Qwen3.8-27B',
        ]);

        // The TUI's next tier-2 read must see the worker's committed model,
        // not its own identity-map snapshot with model = NULL.
        $session = $this->tuiStore->findSession($sessionId);

        $this->assertNotNull($session);
        $this->assertSame('runpod/Qwen3.8-27B', $session->model);
    }

    #[Test]
    public function workerSeesMidRunModelChangeCommittedByTuiProcess(): void
    {
        $sessionId = $this->tuiStore->createSession('/task-start example');
        $this->workerStore->updateMetadata($sessionId, ['model' => 'runpod/Qwen3.8-27B']);
        // Prime the worker's identity map, as a long-lived worker would.
        $this->assertNotNull($this->workerStore->findSession($sessionId));

        // Ctrl+P in the TUI commits a model change mid-run.
        $this->tuiStore->updateMetadata($sessionId, ['model' => 'openai-codex/gpt-6-astra']);

        // The worker's next per-turn resolution read must see the change.
        $session = $this->workerStore->findSession($sessionId);

        $this->assertNotNull($session);
        $this->assertSame('openai-codex/gpt-6-astra', $session->model);
    }

    private function makeStore(EntityManager $em): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new \Ineersa\CodingAgent\Config\AppConfig(
                tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                cwd: $this->tempDir,
            ),
            entityManager: $em,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
    }
}
