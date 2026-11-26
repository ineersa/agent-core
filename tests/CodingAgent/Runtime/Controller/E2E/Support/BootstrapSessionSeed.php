<?php

declare(strict_types=1);
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;

require $argv[1].'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
($container->get(StartupDatabaseMigrator::class))();
$sessions = $container->get(HatfieldSessionStore::class);
$runId = $sessions->createSession('bootstrap fixture');
$sessions->updateMetadata($runId, ['model' => 'llama_cpp_test/test']);
$events = [
    RunEvent::forAppend($runId, 0, 'run_started', ['step_id' => 'seed', 'payload' => [
        'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('old ', 512000)]]]],
        'metadata' => ['model' => 'llama_cpp_test/test', 'session' => []],
    ]]),
    RunEvent::forAppend($runId, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'seed']),
    RunEvent::forAppend($runId, 1, 'agent_end', ['reason' => 'completed']),
];
PreparedEventStoreSeeder::appendMany($container->get(SessionRunEventStore::class), $events);
$path = $sessions->resolveSessionsBasePath().'/'.$runId.'/events.jsonl';
file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($path), '100');
echo json_encode(['run_id' => $runId, 'path' => $path], \JSON_THROW_ON_ERROR)."\n";
$kernel->shutdown();
