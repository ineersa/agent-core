<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Integration;

use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Proves that Messenger buses carry messages to real handlers.
 *
 * Before MessengerIntegrationCompilerPass, all three buses were
 * MessageBus([]) with zero middleware — StartRun messages were
 * silently dropped, events.jsonl was always 0 bytes.
 *
 * This test boots the real kernel in the 'test' environment
 * (which makes InProcessAgentSessionClient public via
 * config/services_test.yaml), uses the production
 * InProcessAgentSessionClient boundary to redirect session
 * storage to a temp dir, dispatches a StartRun, and asserts
 * canonical events are persisted.
 */
class MessengerRuntimeIntegrationTest extends TestCase
{
    /** @var array{dir: string, sessions: string}|null */
    private static ?array $temp = null;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/rtvs07-msg-int-' . getmypid();
        $sessions = $dir . '/.hatfield/sessions';
        @mkdir($sessions, 0o777, true);
        static::$temp = ['dir' => $dir, 'sessions' => $sessions];
    }

    protected function tearDown(): void
    {
        if (null !== static::$temp) {
            $this->removeDirectory(static::$temp['dir']);
            static::$temp = null;
        }
    }

    /**
     * The canonical regression: empty MessageBus([]) silently
     * drops StartRun → events.jsonl stays 0 bytes.
     *
     * With the compiler-pass active, AgentRunner::start()
     * dispatches through agent.command.bus middleware to
     * RunOrchestrator → RunMessageProcessor → StartRunHandler →
     * RunCommit → SessionRunEventStore.
     */
    public function testStartRunPersistsCanonicalEvents(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var InProcessAgentSessionClient $client */
        $client = $container->get('test.runtime_client');
        self::assertInstanceOf(InProcessAgentSessionClient::class, $client);

        // Redirect session storage to temp dir via the production boundary.
        $client->initializeSessionsBasePath(static::$temp['sessions']);

        $request = new StartRunRequest(
            prompt: 'You are a test assistant.',
            runId: 'test-run-42',
            model: null,
            reasoning: null,
        );

        $handle = $client->start($request);
        self::assertSame('test-run-42', $handle->runId);
        self::assertSame('running', $handle->status);

        // Assert canonical events are persisted.
        $eventsPath = static::$temp['sessions'] . '/test-run-42/events.jsonl';
        self::assertFileExists($eventsPath, 'events.jsonl must exist after start');

        $raw = \file_get_contents($eventsPath);
        $lines = \array_values(\array_filter(\explode("\n", $raw)));
        self::assertNotEmpty($lines, 'events.jsonl must contain at least one event');

        $types = \array_map(
            static fn(string $line): string => (string) (\json_decode($line, true)['type'] ?? ''),
            $lines,
        );
        self::assertContains(
            'run_started',
            $types,
            'events.jsonl must contain a run_started event. Found: ' . \implode(', ', $types),
        );
    }

    /**
     * Verify that events() yields mapped RuntimeEvents which
     * are consumable by the RuntimeEventPoller / projection pipeline.
     */
    public function testClientEventsYieldMappedRuntimeEvents(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        /** @var InProcessAgentSessionClient $client */
        $client = $container->get('test.runtime_client');
        $client->initializeSessionsBasePath(static::$temp['sessions']);

        $request = new StartRunRequest(
            prompt: 'You are a helpful assistant.',
            runId: 'test-events-yield',
            model: null,
            reasoning: null,
        );

        $client->start($request);

        $events = \iterator_to_array($client->events('test-events-yield'), false);
        self::assertNotEmpty($events, 'events() must yield mapped RuntimeEvents after start');

        $mappedTypes = \array_map(static fn($e) => $e->type, $events);
        self::assertContains(
            RuntimeEventTypeEnum::RunStarted->value,
            $mappedTypes,
            'Mapped events must include run.started. Found: ' . \implode(', ', $mappedTypes),
        );
    }

    /**
     * Regression: empty MessageBus([]) would silently drop the message.
     * With real middleware, the handler runs and persists events.
     *
     * If this test fails with "no events persisted", the Messenger
     * buses are missing their HandleMessageMiddleware / handlers.
     */
    public function testMessageBusHasRealHandlers(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();

        // agent.command.bus is already public (messenger.yaml).
        $commandBus = $container->get('agent.command.bus');
        self::assertInstanceOf(\Symfony\Component\Messenger\MessageBus::class, $commandBus);

        // The bus should have middleware (HandleMessageMiddleware)
        // — if this is empty, the bus silently drops messages.
        // We verify indirectly by proving start persists events.
        /** @var InProcessAgentSessionClient $client */
        $client = $container->get('test.runtime_client');
        $client->initializeSessionsBasePath(static::$temp['sessions']);

        $request = new StartRunRequest(
            prompt: 'Bus smoke test.',
            runId: 'test-bus-handlers',
        );
        $client->start($request);

        $eventsPath = static::$temp['sessions'] . '/test-bus-handlers/events.jsonl';
        self::assertFileExists($eventsPath);
        self::assertGreaterThan(
            0,
            \filesize($eventsPath),
            'events.jsonl must be non-empty when message bus has real handlers',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                \rmdir($file->getRealPath());
            } else {
                \unlink($file->getRealPath());
            }
        }

        \rmdir($dir);
    }
}
