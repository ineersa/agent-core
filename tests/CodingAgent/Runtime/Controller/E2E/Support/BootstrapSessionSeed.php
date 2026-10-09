<?php

declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

$kernel = new Ineersa\CodingAgent\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
($container->get(Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator::class))();
$sessions = $container->get(Ineersa\CodingAgent\Session\HatfieldSessionStore::class);
$runId = $sessions->createSession('bootstrap fixture');
$sessions->updateMetadata($runId, ['model' => 'llama_cpp_test/test']);
$events = [
    Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($runId, 0, 'run_started', ['step_id' => 'seed', 'payload' => [
        'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('old ', 512000)]]]],
        'metadata' => ['model' => 'llama_cpp_test/test', 'session' => []],
    ]]),
    Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($runId, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'seed']),
    Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($runId, 1, 'agent_end', ['reason' => 'completed']),
];
Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($container->get(Ineersa\CodingAgent\Session\SessionRunEventStore::class), $events);
$path = $sessions->resolveSessionsBasePath().'/'.$runId.'/events.jsonl';
file_put_contents(Ineersa\CodingAgent\Session\FileRunSequenceAllocator::counterPathForEventsLog($path), '100');
echo json_encode(['run_id' => $runId, 'path' => $path], \JSON_THROW_ON_ERROR)."\n";
$kernel->shutdown();
