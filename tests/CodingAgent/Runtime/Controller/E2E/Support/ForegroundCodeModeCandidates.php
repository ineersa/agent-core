<?php

declare(strict_types=1);

use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

require $argv[1].'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    if (isset($argv[2])) {
        echo json_encode($container->get(JsonlProcessAgentSessionClient::class)->foregroundCodeMode($argv[2]), \JSON_THROW_ON_ERROR)."\n";
    } else {
        ($container->get(StartupDatabaseMigrator::class))();
        $sessions = $container->get(HatfieldSessionStore::class);
        $runId = $sessions->createSession('Code-mode backgrounding replay');
        $sessions->updateMetadata($runId, ['model' => 'llama_cpp_test/test']);
        echo $runId."\n";
    }
} finally {
    $kernel->shutdown();
}
