<?php

declare(strict_types=1);

/**
 * Test-only CLI for shared-cache projection setup/assertions under worker DB env.
 *
 * @internal
 */
require dirname(__DIR__, 5).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Tests\Doctrine\Support\SqliteImmediateTransactionKernelTestKernel;

$mode = $argv[1] ?? '';
$runId = $argv[2] ?? '';
$hatfieldCwd = getenv('HATFIELD_CWD');
if ('' === $mode || '' === $runId || !is_string($hatfieldCwd) || '' === $hatfieldCwd) {
    fwrite(\STDERR, "usage: RunControlRecoveryProjectionSetup.php <publish|withdraw|assert-ready> <runId>\n");
    exit(1);
}

$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $hatfieldCwd;
putenv('APP_ENV=test');
putenv('HATFIELD_CWD='.$hatfieldCwd);

foreach ([
    'HATFIELD_TEST_DATABASE_PATH',
    'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH',
    'HATFIELD_CACHE_DIR',
    'HATFIELD_SESSION_ID',
] as $key) {
    $value = getenv($key);
    if (is_string($value) && '' !== $value) {
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }
}

chdir($hatfieldCwd);
StaticDriver::setKeepStaticConnections(false);
SqliteImmediateTransactionKernelTestKernel::bootForSqliteWorker();
$container = SqliteImmediateTransactionKernelTestKernel::getContainerForSqliteWorker();

/** @var RunStateRebuilderInterface $rebuilder */
$rebuilder = $container->get(RunStateRebuilderInterface::class);
/** @var RunStateStoreInterface $store */
$store = $container->get(RunStateStoreInterface::class);

try {
    match ($mode) {
        'publish' => $rebuilder->rebuildIfStale(RunState::queued($runId), $runId),
        'withdraw' => (static function () use ($rebuilder, $store, $runId): void {
            $rebuilder->rebuildIfStale(RunState::queued($runId), $runId);
            if (!$store->isReady($runId)) {
                throw new RuntimeException('publish before withdraw failed');
            }
            $store->withdrawForCommit($runId);
            if ($store->isReady($runId)) {
                throw new RuntimeException('withdraw did not clear readiness');
            }
        })(),
        'assert-ready' => (static function () use ($store, $runId): void {
            if (!$store->isReady($runId)) {
                throw new RuntimeException('shared run state is not ready');
            }
        })(),
        default => throw new InvalidArgumentException('unknown mode: '.$mode),
    };
} catch (Throwable $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");
    exit(2);
}

echo json_encode(['mode' => $mode, 'run_id' => $runId, 'ok' => true], \JSON_THROW_ON_ERROR), "\n";
exit(0);
