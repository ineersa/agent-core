<?php

declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

$kernel = new Ineersa\CodingAgent\Kernel('test', true);
$kernel->boot();
try {
    $client = $kernel->getContainer()->get('test.service_container')->get(Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient::class);
    if (isset($argv[2])) {
        echo json_encode($client->foregroundBash($argv[2]), \JSON_THROW_ON_ERROR)."\n";
    } else {
        $container = $kernel->getContainer()->get('test.service_container');
        ($container->get(Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator::class))();
        $sessions = $container->get(Ineersa\CodingAgent\Session\HatfieldSessionStore::class);
        $runId = $sessions->createSession('Bash detachment replay');
        $sessions->updateMetadata($runId, ['model' => 'llama_cpp_test/test']);
        echo $runId."\n";
    }
} finally {
    $kernel->shutdown();
}
