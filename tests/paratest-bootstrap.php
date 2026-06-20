<?php

declare(strict_types=1);

/**
 * ParaTest per-worker bootstrap.
 *
 * ParaTest spawns N worker processes and assigns each a unique integer
 * TEST_TOKEN.  This bootstrap runs early in each worker — before
 * PHPUnit discovery / Symfony container compilation — and uses the
 * token to isolate:
 *
 *   1. The compiled Symfony cache directory (per worker).
 *   2. The SQLite test database (per worker).
 *
 * WHY PER-WORKER DBs ARE REQUIRED (not just WAL + DAMA):
 *   Even though DAMA/DoctrineTestBundle wraps each test method in a
 *   transaction, multiple workers running KernelTestCase tests
 *   concurrently can issue schema statements (e.g. schema creation by
 *   the kernel boot) that acquire SQLite RESERVED locks.  Two workers
 *   trying to write to the same DB file simultaneously get
 *   "database is locked" because SQLite allows at most ONE writer.
 *   Per-worker DB files eliminate this contention entirely.
 *
 * ── Environment overrides ──
 *   TEST_TOKEN              — set by ParaTest (empty string for main)
 *   HATFIELD_TEST_DATABASE_PATH — per-worker SQLite path
 *   HATFIELD_CACHE_DIR      — per-worker container cache
 */
$token = getenv('TEST_TOKEN') ?: '0';

// ── Per-worker DB path ──
$dbPath = 'app_test-T'.$token.'.sqlite';
putenv("HATFIELD_TEST_DATABASE_PATH={$dbPath}");
$_ENV['HATFIELD_TEST_DATABASE_PATH'] = $dbPath;

// ── Per-worker cache dir ──
$cacheDir = '.hatfield/cache-paraT'.$token;
putenv("HATFIELD_CACHE_DIR={$cacheDir}");
$_ENV['HATFIELD_CACHE_DIR'] = $cacheDir;

// ── Ensure per-worker DB schema ──
// Run the Doctrine migration so this worker's DB is ready before
// any test boots the Symfony kernel.  Failures here are fatal: a
// worker with a missing schema will produce confusing errors.
$phpBin = \PHP_BINARY;
$root = dirname(__DIR__);
@mkdir($root.'/var/test', 0755, true);
$cmd = sprintf(
    'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_CACHE_DIR=%s %s %s/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1',
    escapeshellarg($dbPath),
    escapeshellarg($cacheDir),
    $phpBin,
    escapeshellarg($root)
);
exec($cmd, $output, $exitCode);
if (0 !== $exitCode) {
    fwrite(\STDERR, "ParaTest bootstrap (token={$token}): migration FAILED\n".implode("\n", $output)."\n");
    exit($exitCode);
}
