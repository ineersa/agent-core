<?php

declare(strict_types=1);

use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionCatalogRecoveryService;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$count = (int) $argv[2];
$directory = $argv[1].'/'.$count;
TestDirectoryIsolation::createHatfieldTree($directory);
$paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 4), $directory, 'catalog');
foreach (['HATFIELD_CWD' => $directory, 'APP_SECRET' => 'test-secret',
    'HATFIELD_TEST_DATABASE_PATH' => $paths['appEnv'],
    'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $paths['transportEnv']] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
// Kernel boot derives app.cwd from the process CWD, not only HATFIELD_CWD.
chdir($directory);
$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    // Real startup migrations on a new database, before adding its orphan.
    ($container->get(StartupDatabaseMigrator::class))();
    $store = $container->get(HatfieldSessionStore::class);
    TestDirectoryIsolation::ensureDirectory($directory.'/.hatfield/sessions/42');
    $path = $directory.'/.hatfield/sessions/42/events.jsonl';
    $normalizer = $container->get(EventPayloadNormalizer::class);
    $handle = fopen($path, 'wb');
    if (false === $handle) {
        throw new RuntimeException('Cannot create isolated catalog fixture.');
    }
    try {
        $start = $normalizer->normalize('42', 1, 0, 'run_started', ['step_id' => 'start', 'payload' => [
            'system_prompt' => '',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Fixed catalog prompt']]]],
            'metadata' => ['model' => 'openai/gpt-test', 'reasoning' => 'medium', 'session' => ['parent_run_id' => '12']],
        ]]);
        $start['ts'] = '2026-01-02T00:00:00+00:00';
        fwrite($handle, json_encode($start, \JSON_THROW_ON_ERROR)."\n");
        unset($start);
        for ($index = 1; $index <= $count; ++$index) {
            // Sparse sequences and non-monotone dates must keep catalog semantics.
            $record = $normalizer->normalize('42', 2 * $index + 1, 1, 'turn_advanced', ['turn_no' => 1, 'text' => str_repeat('x', 4096)]);
            $record['ts'] = 0 === $index % 2 ? '2026-01-03T00:00:00+00:00' : '2026-01-01T00:00:00+00:00';
            fwrite($handle, json_encode($record, \JSON_THROW_ON_ERROR)."\n");
            unset($record);
        }
    } finally {
        fclose($handle);
    }
    $bytes = filesize($path);
    $hash = hash_file('sha256', $path);
    $fresh = null === $store->findSession('42');
    $recovery = $container->get(SessionCatalogRecoveryService::class);
    $recovery();
    $session = $store->findSession('42');
    if (null === $session) {
        throw new RuntimeException('Cold catalog recovery did not admit the orphan.');
    }
    $key = $session->providerCacheKey;
    $recovery();
    $again = $store->findSession('42');
    echo json_encode(['count' => $count, 'archive_bytes' => $bytes, 'recovered_bytes' => filesize($path),
        'source_hash' => $hash, 'recovered_hash' => hash_file('sha256', $path),
        'fresh_orphan' => $fresh, 'prompt' => $session->prompt, 'name' => $session->name,
        'model' => $session->model, 'reasoning' => $session->reasoning, 'parent_id' => $session->parentId,
        'created_at' => $session->createdAt->format('Y-m-d H:i:s'), 'updated_at' => $session->updatedAt->format('Y-m-d H:i:s'),
        'provider_uuid_v7' => Uuid::fromString((string) $key) instanceof UuidV7,
        'provider_cache_key' => $key, 'repeated_provider_cache_key' => $again?->providerCacheKey,
        'peak_bytes' => memory_get_peak_usage(true)], \JSON_THROW_ON_ERROR)."\n";
} finally {
    $kernel->shutdown();
}
