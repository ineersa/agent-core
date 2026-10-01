<?php

declare(strict_types=1);

/**
 * Test-only CLI entry for persistent shared-startup cache reuse.
 * Invoked from SharedStartupFreshnessIntegrationTest subprocesses.
 *
 * @internal
 */
require dirname(__DIR__, 5).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\Replay\SessionRunStateReplayService;
use Ineersa\CodingAgent\Session\RunState\CacheRunStateStore;
use Ineersa\CodingAgent\Tests\Doctrine\Support\SqliteImmediateTransactionKernelTestKernel;
use Ineersa\CodingAgent\Tests\Support\SessionColdReconstructionTestFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

$mode = $argv[1] ?? '';
$runId = $argv[2] ?? '';
if ('' === $mode || '' === $runId) {
    fwrite(\STDERR, "usage: SharedStartupCacheReuseKernelWorker.php <publish|reuse> <runId>\n");
    exit(1);
}

$hatfieldCwd = getenv('HATFIELD_CWD');
if (!is_string($hatfieldCwd) || '' === $hatfieldCwd) {
    fwrite(\STDERR, "HATFIELD_CWD required\n");
    exit(1);
}

$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $hatfieldCwd;
putenv('APP_ENV=test');
putenv('HATFIELD_CWD='.$hatfieldCwd);

foreach (['HATFIELD_TEST_DATABASE_PATH', 'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH', 'HATFIELD_CACHE_DIR'] as $key) {
    $value = getenv($key);
    if (is_string($value) && '' !== $value) {
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }
}

chdir($hatfieldCwd);

migrateWorkerDatabases($hatfieldCwd);

// Subprocess kernels disable DAMA static connections so cache.app writes are
// durable and visible to the sibling reuse process.
StaticDriver::setKeepStaticConnections(false);

SqliteImmediateTransactionKernelTestKernel::bootForSqliteWorker();
$container = SqliteImmediateTransactionKernelTestKernel::getContainerForSqliteWorker();

/** @var Psr\Cache\CacheItemPoolInterface $pool */
$pool = $container->get('cache.app');
/** @var LockFactory $locks */
$locks = $container->get(LockFactory::class);
/** @var NormalizerInterface&DenormalizerInterface $serializer */
$serializer = $container->get('serializer');
/** @var RunOperationalProjectionRepository $projectionRepository */
$projectionRepository = $container->get(RunOperationalProjectionRepository::class);

$runLock = new RunLockManager($locks);
$historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
$runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
$active = new ActiveRunContext($runStateStore, $projectionRepository, $runLock, $historyStore);

try {
    $payload = match ($mode) {
        'publish' => publishReadyProjections($runId, $historyStore, $runStateStore, $active),
        'reuse' => reuseReadyProjections($runId, $historyStore, $runStateStore, $runLock),
        default => throw new InvalidArgumentException('unknown mode: '.$mode),
    };
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(2);
}

echo json_encode($payload, \JSON_THROW_ON_ERROR), "\n";
exit(0);

/**
 * @return array{last_seq: int, turn_no: int, ready_state: bool, ready_history: bool}
 */
function publishReadyProjections(
    string $runId,
    CacheHistoryProjectionStore $historyStore,
    CacheRunStateStore $runStateStore,
    ActiveRunContext $active,
): array {
    $store = new InMemoryEventStore();
    seedDiscardedHistorySession($store, $runId);
    $cold = SessionColdReconstructionTestFactory::create(
        eventStore: $store,
        historyStore: $historyStore,
        activeRunContext: $active,
        runStateStore: $runStateStore,
    );
    $result = $cold->reconstruct(
        runId: $runId,
        publishSharedState: true,
        publishHistory: true,
    );

    return [
        'last_seq' => $result->lastSeq,
        'turn_no' => $result->runState->turnNo,
        'ready_state' => $runStateStore->isReady($runId),
        'ready_history' => $historyStore->get($runId)->ready,
    ];
}

/**
 * @return array{
 *     last_seq: int,
 *     turn_no: int,
 *     range_for_calls: int,
 *     latest_sequence_for_calls: int,
 *     all_for_calls: int,
 * }
 */
function reuseReadyProjections(
    string $runId,
    CacheHistoryProjectionStore $historyStore,
    CacheRunStateStore $runStateStore,
    RunLockManager $runLock,
): array {
    $countingStore = new class implements EventStoreInterface {
        public int $allForCalls = 0;
        public int $latestSequenceForCalls = 0;
        public int $rangeForCalls = 0;

        public function append(RunEvent $event): RunEvent
        {
            throw new RuntimeException('append must not run during ready shared reuse.');
        }

        public function appendMany(array $events): array
        {
            throw new RuntimeException('appendMany must not run during ready shared reuse.');
        }

        public function latestSequenceFor(string $runId): ?int
        {
            ++$this->latestSequenceForCalls;

            throw new RuntimeException('latestSequenceFor must not run during ready shared reuse.');
        }

        public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
        {
            ++$this->rangeForCalls;

            throw new RuntimeException('rangeFor must not run during ready shared reuse.');
        }

        public function readAfterSeq(string $runId, int $cursor): array
        {
            $events = [];
            foreach ($this->rangeFor($runId, 1, \PHP_INT_MAX) as $event) {
                if ($event->seq > $cursor) {
                    $events[] = $event;
                }
            }

            return $events;
        }
    };
    $cold = SessionColdReconstructionTestFactory::create(
        eventStore: $countingStore,
        historyStore: $historyStore,
        runStateStore: $runStateStore,
    );
    $replay = new SessionRunStateReplayService(
        $countingStore,
        new NullLogger(),
        $cold,
        $historyStore,
        $runStateStore,
        $runLock,
    );

    $result = $replay->rebuildIfStale(RunState::queued($runId), $runId);
    if (null === $result->rebuiltState) {
        throw new RuntimeException('Expected ready shared projections to rebuild the queued attach cursor.');
    }

    return [
        'last_seq' => $result->rebuiltState->lastSeq,
        'turn_no' => $result->rebuiltState->turnNo,
        'range_for_calls' => $countingStore->rangeForCalls,
        'latest_sequence_for_calls' => $countingStore->latestSequenceForCalls,
        'all_for_calls' => $countingStore->allForCalls,
    ];
}

function seedDiscardedHistorySession(InMemoryEventStore $store, string $runId): void
{
    $events = [
        event($runId, 'run_started', 1, 0, ['payload' => ['messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Prompt A']]],
        ]]]),
        event($runId, 'turn_advanced', 2, 1, ['turn_no' => 1]),
        event($runId, 'history_position_set', 3, 1, [
            'position_turn_no' => 1,
            'previous_position_turn_no' => null,
            'reason' => 'continue',
        ]),
        event($runId, 'llm_step_completed', 4, 1, assistantPayload('Answer A', 'step-a')),
        event($runId, 'turn_advanced', 5, 2, ['turn_no' => 2]),
        event($runId, 'history_position_set', 6, 2, [
            'position_turn_no' => 2,
            'previous_position_turn_no' => 1,
            'reason' => 'continue',
        ]),
        event($runId, 'llm_step_completed', 7, 2, assistantPayload('Answer B discarded', 'step-b')),
        event($runId, 'history_position_set', 8, 1, [
            'position_turn_no' => 1,
            'previous_position_turn_no' => 2,
            'reason' => 'history_select',
        ]),
        event($runId, RunEventTypeEnum::HistoryTailDiscarded->value, 9, 1, ['after_turn_no' => 1]),
        event($runId, 'turn_advanced', 10, 3, ['turn_no' => 3]),
        event($runId, 'history_position_set', 11, 3, [
            'position_turn_no' => 3,
            'previous_position_turn_no' => 1,
            'reason' => 'continue',
        ]),
        event($runId, 'llm_step_completed', 12, 3, assistantPayload('Answer C active', 'step-c')),
    ];
    foreach ($events as $event) {
        $store->seed($event);
    }
}

/** @return array<string, mixed> */
function assistantPayload(string $text, string $stepId): array
{
    return [
        'step_id' => $stepId,
        'assistant_message' => [
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
        ],
    ];
}

/** @param array<string, mixed> $payload */
function event(string $runId, string $type, int $seq, int $turnNo, array $payload): RunEvent
{
    return new RunEvent(
        runId: $runId,
        seq: $seq,
        turnNo: $turnNo,
        type: $type,
        payload: $payload,
        createdAt: new DateTimeImmutable(sprintf('2026-09-30T00:00:%02d+00:00', $seq)),
    );
}

function migrateWorkerDatabases(string $hatfieldCwd): void
{
    $projectDir = dirname(__DIR__, 5);
    $php = \PHP_BINARY;
    $console = $projectDir.'/bin/console';
    $env = array_merge($_ENV, [
        'APP_ENV' => 'test',
        'HATFIELD_CWD' => $hatfieldCwd,
    ]);

    $commands = [
        [$php, $console, 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
        [
            $php,
            $console,
            'doctrine:migrations:migrate',
            '--em=messenger_transport',
            '--configuration=config/migrations/messenger_transport.yaml',
            '--no-interaction',
            '--allow-no-migration',
        ],
    ];

    foreach ($commands as $command) {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($command, $spec, $pipes, $projectDir, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to start worker database migration.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (0 !== $exit) {
            throw new RuntimeException(trim($stderr."\n".$stdout) ?: 'Worker database migration failed.');
        }
    }
}
