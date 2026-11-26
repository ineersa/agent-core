<?php

declare(strict_types=1);
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\CodingAgent\Kernel;

require dirname(__DIR__, 4).'/vendor/autoload.php';

chdir($argv[1]);
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $argv[1];
putenv('HATFIELD_CWD='.$argv[1]);
$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $container->get(RunLockManager::class)->synchronized($argv[2], static function () use ($container, $argv): void {
        $container->get(PendingTransitionRecovery::class)->recover($argv[2]);
    });
    fwrite(\STDOUT, "recovered\n");
} finally {
    $kernel->shutdown();
}
