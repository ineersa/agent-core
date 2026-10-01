<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$cacheDir = $argv[1] ?? '';
$lockDir = $argv[2] ?? '';
$runId = $argv[3] ?? '';
$conflictLog = $argv[4] ?? '';
$joinTransitionLock = ($argv[5] ?? '1') === '1';
if ('' === $cacheDir || '' === $lockDir || '' === $runId || '' === $conflictLog) {
    fwrite(\STDERR, "usage: history-projection-commit-coherence-reader.php <cacheDir> <lockDir> <runId> <conflictLog> [joinTransitionLock=1]\n");
    exit(1);
}

$locks = new LockFactory(new FlockStore($lockDir));
$locks->setLogger(new class($conflictLog) extends AbstractLogger {
    public function __construct(private readonly string $conflictLog)
    {
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $resource = $context['resource'] ?? null;
        if (is_object($resource) && method_exists($resource, '__toString')) {
            $resource = (string) $resource;
        } elseif (!is_scalar($resource) && null !== $resource) {
            $resource = get_debug_type($resource);
        }

        $line = json_encode([
            'level' => (string) $level,
            'message' => (string) $message,
            'resource' => $resource,
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($this->conflictLog, $line."\n", \FILE_APPEND | \LOCK_EX);
    }
});

$runLock = new RunLockManager($locks, ttlSeconds: 10.0, acquireTimeoutSeconds: 3.0);
$store = new CacheHistoryProjectionStore(
    pool: new FilesystemAdapter(namespace: 'hist', defaultLifetime: 0, directory: $cacheDir),
    lockFactory: $locks,
    projector: new HistoryProjector(),
    runLockManager: $runLock,
);

try {
    if ($joinTransitionLock) {
        $reader = new RunStartedMetadataReader($store);
        $metadata = $reader->readRunStartedMetadata($runId);
        $snapshot = $store->get($runId);
        echo json_encode([
            'allowed_tools' => $metadata?->allowedToolsForChild(),
            'last_seq' => $snapshot->lastSeq,
            'ready' => $snapshot->ready,
        ], \JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }

    // Counterfactual: observe readiness under the history lock only, skipping
    // the production transition-lock join that get() performs. This must fail
    // while a healthy commit still holds the withdrawn readiness window.
    $historyLock = $locks->createLock('hatfield-history-projection-'.$runId);
    $historyLock->acquire(true);
    try {
        $ref = new ReflectionClass(CacheHistoryProjectionStore::class);
        $readCache = $ref->getMethod('readCache');
        /** @var HistoryProjectionSnapshot|null $cached */
        $cached = $readCache->invoke($store, $runId);
        if (null === $cached) {
            throw new RuntimeException(sprintf('History projection missing for run %s; initialize via startup/recovery before ordinary lookups.', $runId));
        }
        if (!$cached->ready) {
            throw new RuntimeException(sprintf('History projection for run %s is not ready; recovery required.', $runId));
        }

        echo json_encode([
            'allowed_tools' => $cached->runStartedLaunch?->allowedTools,
            'last_seq' => $cached->lastSeq,
            'ready' => $cached->ready,
        ], \JSON_THROW_ON_ERROR), "\n";
        exit(0);
    } finally {
        $historyLock->release();
    }
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception::class.': '.$exception->getMessage()."\n");
    echo json_encode([
        'error' => $exception->getMessage(),
        'error_type' => $exception::class,
    ], \JSON_THROW_ON_ERROR), "\n";
    exit(2);
}
