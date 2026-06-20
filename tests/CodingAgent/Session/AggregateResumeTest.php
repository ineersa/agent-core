<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Integration test proving that run state and events survive
 * process/container recreation (simulates restart).
 */
final class AggregateResumeTest extends TestCase
{
    private string $projectDir = '';
    private HatfieldSessionStore $hatfieldSessionStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-aggregate-resume-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $this->hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: self::createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    /**
     * Simulates a full resume-after-restart cycle:
     * 1. Create run state + events via store
     * 2. Destroy store instances
     * 3. Create new store instances (simulating new container)
     * 4. Verify all data survives
     */
    public function testRunStateAndEventsSurviveRecreation(): void
    {
        $runId = 'resume-test-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);

        // Phase 1: Write data
        $serializer1 = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
        $lockFactory1 = new LockFactory(new FlockStore());
        $normalizer1 = new EventPayloadNormalizer();

        $nullLogger = new \Psr\Log\NullLogger();

        $runStore1 = new SessionRunStore($this->hatfieldSessionStore, $serializer1, $lockFactory1);
        $eventStore1 = new SessionRunEventStore($this->hatfieldSessionStore, $normalizer1, $lockFactory1, $nullLogger);

        // Create run state
        $initialState = new RunState(runId: $runId, status: RunStatus::Queued, version: 1);
        $casResult = $runStore1->compareAndSwap($initialState, 0);
        self::assertTrue($casResult, 'First CAS must succeed');

        // Append events
        $eventStore1->append(new RunEvent(runId: $runId, seq: 1, turnNo: 0, type: 'run_started'));
        $eventStore1->append(new RunEvent(runId: $runId, seq: 2, turnNo: 1, type: 'tool_execution_start'));

        // Phase 2: Destroy everything (simulate process restart)
        unset($runStore1, $eventStore1, $serializer1, $lockFactory1, $normalizer1);

        // Phase 3: Create fresh store instances
        $serializer2 = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
        $lockFactory2 = new LockFactory(new FlockStore());
        $normalizer2 = new EventPayloadNormalizer();

        $runStore2 = new SessionRunStore($this->hatfieldSessionStore, $serializer2, $lockFactory2);
        $eventStore2 = new SessionRunEventStore($this->hatfieldSessionStore, $normalizer2, $lockFactory2, $nullLogger);

        // Phase 4: Verify state survives
        $loadedState = $runStore2->get($runId);
        self::assertNotNull($loadedState, 'RunState must survive store recreation');
        self::assertSame($runId, $loadedState->runId);
        self::assertSame(RunStatus::Queued, $loadedState->status);
        self::assertSame(1, $loadedState->version);

        // Phase 5: Verify events survive
        $events = $eventStore2->allFor($runId);
        self::assertCount(2, $events, 'Events must survive store recreation');
        self::assertSame('run_started', $events[0]->type);
        self::assertSame('tool_execution_start', $events[1]->type);
        self::assertSame(1, $events[0]->seq);
        self::assertSame(2, $events[1]->seq);

        // Phase 6: Continue the run (CAS to next version)
        $nextState = new RunState(runId: $runId, status: RunStatus::Running, version: 2, turnNo: 1);
        $casResult2 = $runStore2->compareAndSwap($nextState, 1);
        self::assertTrue($casResult2, 'CAS after resume must succeed');

        $finalState = $runStore2->get($runId);
        self::assertNotNull($finalState);
        self::assertSame(RunStatus::Running, $finalState->status);
        self::assertSame(2, $finalState->version);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir((string) $item);
            } else {
                unlink((string) $item);
            }
        }
        rmdir($dir);
    }
}
