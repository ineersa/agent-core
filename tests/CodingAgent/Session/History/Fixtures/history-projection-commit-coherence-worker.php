<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$cacheDir = $argv[1] ?? '';
$lockDir = $argv[2] ?? '';
$runId = $argv[3] ?? '';
$withdrawnMarker = $argv[4] ?? '';
$releaseMarker = $argv[5] ?? '';
if ('' === $cacheDir || '' === $lockDir || '' === $runId || '' === $withdrawnMarker || '' === $releaseMarker) {
    fwrite(\STDERR, "usage: history-projection-commit-coherence-worker.php <cacheDir> <lockDir> <runId> <withdrawnMarker> <releaseMarker>\n");
    exit(1);
}

$locks = new LockFactory(new FlockStore($lockDir));
$runLock = new RunLockManager($locks, ttlSeconds: 10.0, acquireTimeoutSeconds: 3.0);
$store = new CacheHistoryProjectionStore(
    pool: new FilesystemAdapter(namespace: 'hist', defaultLifetime: 0, directory: $cacheDir),
    lockFactory: $locks,
    projector: new HistoryProjector(),
    runLockManager: $runLock,
);

$runLock->synchronized($runId, static function () use ($store, $runId, $withdrawnMarker, $releaseMarker): void {
    $store->withdrawForCommit($runId);

    if (false === file_put_contents($withdrawnMarker, 'W')) {
        throw new RuntimeException('Cannot write withdrawn marker');
    }

    $deadline = microtime(true) + 4.0;
    while (!is_file($releaseMarker)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for release marker while holding transition lock');
        }
        usleep(5_000);
    }

    $store->applyCommitted($runId, [
        new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 0,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: [
                'tool_result' => [
                    'tool_call_id' => 'call-read',
                    'is_error' => false,
                    'result' => ['content' => [['type' => 'text', 'text' => 'ok']]],
                ],
            ],
            createdAt: new DateTimeImmutable(),
        ),
    ]);
});

echo json_encode(['ok' => true, 'last_seq' => $store->get($runId)->lastSeq], \JSON_THROW_ON_ERROR), "\n";
