<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Exception\BackgroundProcessUnavailableException;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\ControlledJsonlControllerFixture;
use Ineersa\CodingAgent\Tests\Support\ExplicitSessionStoreFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Support\UnusedBackgroundProcessManagerFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use PHPUnit\Framework\Attributes\DataProvider;

final class JsonlProcessAgentSessionClientStopTest extends IsolatedKernelTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function outcomes(): iterable
    {
        yield 'stopped' => [true, true];
        yield 'already finished' => [true, false];
        yield 'unavailable' => [false, false];
    }

    #[DataProvider('outcomes')]
    public function testStopRoundTripsWithoutLocalPersistenceAndPreservesPeerEvents(bool $available, bool $stopped): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('jsonl-stop');
        $script = $directory.'/controller.php';
        file_put_contents($directory.'/outcome.json', json_encode(['available' => $available, 'stopped' => $stopped], \JSON_THROW_ON_ERROR));
        file_put_contents($script, <<<'PHP'
<?php
function emit(array $event): void { fwrite(STDOUT, json_encode($event)."\n"); fflush(STDOUT); }
emit(['type' => 'runtime.ready', 'runId' => '', 'seq' => 0, 'payload' => []]);
$start = json_decode(fgets(STDIN), true);
emit(['type' => 'run.started', 'runId' => $start['runId'], 'seq' => 1, 'payload' => []]);
$stop = json_decode(fgets(STDIN), true);
file_put_contents(__DIR__.'/stop.json', json_encode($stop));
$outcome = json_decode(file_get_contents(__DIR__.'/outcome.json'), true);
// Same command ID with another run must not satisfy the waiter.
emit(['type' => 'bg_process.stop.completed', 'runId' => 'other', 'seq' => 0, 'payload' => ['commandId' => $stop['id'], 'available' => true, 'stopped' => !$outcome['stopped']]]);
$batch = [
    ['type' => 'command.ack', 'runId' => $stop['runId'], 'seq' => 0, 'payload' => ['commandId' => $stop['id']]],
    ['type' => 'bg_process.stop.completed', 'runId' => $stop['runId'], 'seq' => 0, 'payload' => ['commandId' => $stop['id']] + $outcome],
    ['type' => 'run.completed', 'runId' => $stop['runId'], 'seq' => 2, 'payload' => []],
];
fwrite(STDOUT, implode("\n", array_map('json_encode', $batch))."\n"); fflush(STDOUT);
while (false !== fgets(STDIN)) {}
PHP);
        $client = $this->createClient($script, $directory);
        try {
            $client->start(new StartRunRequest(prompt: 'test', runId: 'stop-run'));
            try {
                $this->assertSame($stopped, $client->stopBackgroundProcess('stop-run', 42));
                $this->assertTrue($available, 'Unavailable outcome must throw.');
            } catch (BackgroundProcessUnavailableException) {
                $this->assertFalse($available);
            }
            $command = json_decode((string) file_get_contents($directory.'/stop.json'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame('stop_bash', $command['type']);
            $this->assertSame(['record_id' => 42], $command['payload']);
            $types = array_map(static fn ($event): string => $event->type, iterator_to_array($client->events('stop-run')));
            $this->assertContains('run.completed', $types);
        } finally {
            $client->shutdown();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testColdStopRefusesWithoutStartingControllerOrUsingLocalPersistence(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('jsonl-stop-cold');
        $script = $directory.'/controller.php';
        file_put_contents($script, '<?php file_put_contents(__DIR__."/unexpected-start", "started");');
        $client = $this->createClient($script, $directory);
        try {
            try {
                $client->stopBackgroundProcess('stop-run', 42);
                $this->fail('A cold client cannot stop background work.');
            } catch (BackgroundProcessUnavailableException $exception) {
                $this->assertSame('Controller unavailable; background process was not stopped.', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($directory.'/unexpected-start');
        } finally {
            $client->shutdown();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testDisconnectedStopRefusesWithoutReplacingController(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('jsonl-stop-disconnected');
        $fixture = new ControlledJsonlControllerFixture($directory);
        $client = $this->createClient($fixture->script, $directory);
        try {
            $client->start(new StartRunRequest(prompt: 'test', runId: 'stop-run'));
            $fixture->connect();
            $fixture->emit('', exit: true);
            $fixture->awaitExit($client);
            try {
                $client->stopBackgroundProcess('stop-run', 42);
                $this->fail('A disconnected client cannot stop background work.');
            } catch (BackgroundProcessUnavailableException $exception) {
                $this->assertSame('Controller unavailable; background process was not stopped.', $exception->getMessage());
            }
            $this->assertSame(['spawn', 'start_run'], $fixture->commandTypes());
        } finally {
            $client->shutdown();
            $fixture->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function createClient(string $script, string $directory): JsonlProcessAgentSessionClient
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('getConnection');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('getManagerForClass');
        $locator = new class($script) implements AppExecutableLocator {
            public function __construct(private readonly string $script)
            {
            }

            public function command(): array
            {
                return [\PHP_BINARY, $this->script];
            }

            public function path(): string
            {
                return $this->script;
            }
        };

        return new JsonlProcessAgentSessionClient(
            backgroundProcesses: UnusedBackgroundProcessManagerFactory::create($entityManager, $registry),
            runtimeConfig: new RuntimeProcessConfig(executableLocator: $locator, runtimeCwd: $directory),
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            toolFilterConfig: new ToolFilterRuntimeConfig(),
            logger: new TestLogger(),
            sessionStore: ExplicitSessionStoreFactory::create($this->createStub(EntityManagerInterface::class), $directory),
            childBackground: self::getContainer()->get(ChildInvocationBackgroundService::class),
            backgroundWork: self::getContainer()->get(BackgroundWorkSnapshotProvider::class),
        );
    }
}
