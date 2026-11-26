<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tool\Arguments\BgStatusArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;

require $argv[1].'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $client = $kernel->getContainer()->get('test.service_container')->get(JsonlProcessAgentSessionClient::class);
    if ('code_mode' === ($argv[3] ?? null)) {
        $snapshot = (array) $client->backgroundWork($argv[2]);
        $snapshot['sessionModel'] = $kernel->getContainer()->get('test.service_container')->get(HatfieldSessionStore::class)->findSession($argv[2])?->model;
        $snapshot['inFlight'] = $kernel->getContainer()->get('test.service_container')->get(Connection::class)->fetchFirstColumn('SELECT in_flight_json FROM tool_batch WHERE run_id = ?', [$argv[2]]);
        echo json_encode($snapshot, \JSON_THROW_ON_ERROR)."\n";
    } elseif (isset($argv[3], $argv[4])) {
        file_put_contents($argv[4], (string) getmypid());
        $container = $kernel->getContainer()->get('test.service_container');
        $process = $container->get(BackgroundProcessManager::class)->findByRecordId((int) $argv[3], $argv[2]);
        $tool = $container->get(BgStatusTool::class);
        $context = new ToolContext($argv[2], 0, 'stop-worker', 'bg_status', new NullCancellationToken(), 30);
        echo $container->get(StackToolExecutionContextAccessor::class)->with($context,
            static fn (): string => $tool(new BgStatusArgumentsDTO(action: 'stop', pid: $process->pid)));
    } elseif (isset($argv[2])) {
        echo json_encode($client->foregroundBash($argv[2]), \JSON_THROW_ON_ERROR)."\n";
    } else {
        $container = $kernel->getContainer()->get('test.service_container');
        ($container->get(StartupDatabaseMigrator::class))();
        $sessions = $container->get(HatfieldSessionStore::class);
        $runId = $sessions->createSession('Bash detachment replay');
        $sessions->updateMetadata($runId, ['model' => 'llama_cpp_test/test']);
        echo $runId."\n";
    }
} finally {
    $kernel->shutdown();
}
