<?php

declare(strict_types=1);

/**
 * Test-only CLI: boot a kernel and run messenger:consume run_control for recovery proofs.
 *
 * Production WorkerStarted subscribers own exclusive ownership and startup/recovery.
 * This fixture only injects the OOM-once middleware; it does not pre-rebuild state.
 *
 * @internal
 */
require dirname(__DIR__, 5).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Ineersa\CodingAgent\Tests\Doctrine\Support\SqliteImmediateTransactionKernelTestKernel;
use Ineersa\CodingAgent\Tests\Runtime\Messenger\Support\RunControlRecoveryAdvanceMiddleware;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;

$hatfieldCwd = getenv('HATFIELD_CWD');
$sessionId = getenv('HATFIELD_SESSION_ID');
if (!is_string($hatfieldCwd) || '' === $hatfieldCwd || !is_string($sessionId) || '' === $sessionId) {
    fwrite(\STDERR, "HATFIELD_CWD and HATFIELD_SESSION_ID required\n");
    exit(1);
}

$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $hatfieldCwd;
$_ENV['HATFIELD_SESSION_ID'] = $sessionId;
putenv('APP_ENV=test');
putenv('HATFIELD_CWD='.$hatfieldCwd);
putenv('HATFIELD_SESSION_ID='.$sessionId);

foreach ([
    'HATFIELD_TEST_DATABASE_PATH',
    'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH',
    'HATFIELD_CACHE_DIR',
    'HATFIELD_TEST_RUN_CONTROL_OOM_ONCE_PATH',
    'HATFIELD_RUN_CONTROL_TRANSPORT_DSN',
    'HATFIELD_LLM_TRANSPORT_DSN',
    'HATFIELD_TOOL_TRANSPORT_DSN',
    'HATFIELD_AGENT_TRANSPORT_DSN',
    'HATFIELD_MCP_TRANSPORT_DSN',
    'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN',
] as $key) {
    $value = getenv($key);
    if (is_string($value) && '' !== $value) {
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }
}

chdir($hatfieldCwd);
migrateWorkerDatabases($hatfieldCwd);

// Hard ceiling matches ConsumerSupervisor's messenger --memory-limit so an
// intentional allocation storm can fatal before Symfony's soft recycle check.
ini_set('memory_limit', '128M');

StaticDriver::setKeepStaticConnections(false);
SqliteImmediateTransactionKernelTestKernel::bootForSqliteWorker();
$container = SqliteImmediateTransactionKernelTestKernel::getContainerForSqliteWorker();

/** @var MessageBusInterface $bus */
$bus = $container->get('agent.command.bus');
if (!$bus instanceof MessageBus) {
    fwrite(\STDERR, "agent.command.bus must be Symfony MessageBus for recovery middleware injection\n");
    exit(1);
}

$middlewareProperty = new ReflectionProperty(MessageBus::class, 'middlewareAggregate');
$existing = iterator_to_array($middlewareProperty->getValue($bus), false);
$middlewareProperty->setValue($bus, new ArrayObject(array_values(array_merge(
    [new RunControlRecoveryAdvanceMiddleware()],
    $existing,
))));

$application = new Symfony\Bundle\FrameworkBundle\Console\Application(
    SqliteImmediateTransactionKernelTestKernel::getKernelForSqliteWorker(),
);
$application->setAutoExit(false);

$input = new ArrayInput([
    'command' => 'messenger:consume',
    'receivers' => ['run_control'],
    '--no-interaction' => true,
    '--memory-limit' => '128M',
    '--sleep' => '0.05',
    '--limit' => '1',
]);

$exit = $application->run($input, new ConsoleOutput());
exit($exit);

function migrateWorkerDatabases(string $hatfieldCwd): void
{
    $projectDir = dirname(__DIR__, 5);
    $php = \PHP_BINARY;
    $console = $projectDir.'/bin/console';
    $env = array_merge($_ENV, [
        'APP_ENV' => 'test',
        'HATFIELD_CWD' => $hatfieldCwd,
    ]);

    $commands = [
        [$php, $console, 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
        [
            $php,
            $console,
            'doctrine:migrations:migrate',
            '--em=messenger_transport',
            '--configuration=config/migrations/messenger_transport.yaml',
            '--no-interaction',
            '--allow-no-migration',
        ],
    ];

    foreach ($commands as $command) {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($command, $spec, $pipes, $projectDir, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to start worker database migration.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (0 !== $exit) {
            throw new RuntimeException(trim($stderr."\n".$stdout) ?: 'Worker database migration failed.');
        }
    }
}
