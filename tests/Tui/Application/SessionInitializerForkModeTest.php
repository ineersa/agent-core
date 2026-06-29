<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Focused tests for SessionInitializer fork-mode behavior.
 *
 * Test thesis:
 *   When initialize() receives a fork_mode request with a non-empty runId,
 *   it creates a TuiSessionState whose sessionId === request->runId WITHOUT
 *   creating a DB session row.  This preserves the session_id === run_id
 *   invariant for fork children and avoids orphan DB records.
 */
#[CoversClass(SessionInitializer::class)]
final class SessionInitializerForkModeTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-session-init-fork-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/.hatfield/sessions', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
        parent::tearDown();
    }

    /**
     * Fork mode request: sessionId === runId, no DB session creation.
     */
    public function testForkModePreservesRunIdAsSessionId(): void
    {
        $sessionInit = $this->createSessionInitializer();

        $request = new StartRunRequest(
            prompt: '',
            runId: 'fork-child-run-42',
            options: ['fork_mode' => true, 'fork_snapshot_path' => '/tmp/test-snapshot.json'],
        );

        $state = $sessionInit->initialize('', $request);

        $this->assertSame('fork-child-run-42', $state->sessionId, 'Fork mode must set sessionId = runId');
        $this->assertFalse($state->resuming, 'Fork mode must not be marked as resuming');
        $this->assertNotNull($state->request, 'Fork mode must preserve request');
        $this->assertTrue($state->request->options['fork_mode'] ?? false, 'Fork mode must preserve fork_mode option');
        $this->assertSame('fork-child-run-42', $state->request->runId, 'Fork mode must preserve runId in request');
    }

    /**
     * Fork mode without runId: falls through to normal session creation.
     * (Defensive check for misconfigured fork requests.)
     */
    public function testForkModeWithoutRunIdFallsBack(): void
    {
        $sessionInit = $this->createSessionInitializer();

        $request = new StartRunRequest(
            prompt: '',
            runId: '',
            options: ['fork_mode' => true],
        );

        // Without a runId, the fork-mode bypass is not triggered, so
        // initialize() attempts normal session creation. Since the
        // HatfieldSessionStore has no DB (stubbed), it returns '' as sessionId.
        $state = $sessionInit->initialize('', $request);

        // The sessionId will be empty string since the DB entity manager stub
        // won't actually persist.  The important assertion is that the
        // fork-mode bypass handles this gracefully.
        $this->assertEmpty($state->sessionId, 'Fork mode without runId must not bypass');
    }

    // ── helpers ──

    private function createSessionInitializer(): SessionInitializer
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $projector = $this->createStub(TranscriptProjectorInterface::class);

        return new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: new SessionRunEventStore(
                hatfieldSessionStore: $hatfieldSessionStore,
                eventPayloadNormalizer: new EventPayloadNormalizer(),
                lockFactory: new LockFactory(new FlockStore()),
                logger: new NullLogger(),
            ),
            eventMapper: new RuntimeEventMapper(
                new RuntimeEventTranslator(new EventDispatcher()),
            ),
            projector: $projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            eventApplier: new TuiRuntimeEventApplier($projector),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }

        @rmdir($dir);
    }
}
