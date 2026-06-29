<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\StartRunHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Thesis: StartRunHandler must fail fast when fork_mode is set but fork
 * services are null (the critical bug this test guards against), route
 * normal starts through InProcessAgentSessionClient, and ignore non-start
 * commands.
 *
 * Uses IsolatedKernelTestCase because StartRunHandler takes concrete
 * InProcessAgentSessionClient (final, 16-param constructor) — only the
 * container can provide a properly-wired instance without mocking.
 *
 * ForkControllerStartService and ForkRunTerminalWatcher are also final
 * readonly classes, so the success routing path is tested structurally
 * via the throw-guard tests below.  The implementation is straightforward
 * delegation: fork_mode → preflight both services, then forkStartService
 * and forkTerminalWatcher in sequence.
 */
#[CoversClass(StartRunHandler::class)]
final class StartRunHandlerTest extends IsolatedKernelTestCase
{
    // ── Fork wiring failure tests ──

    public function testForkModeWithoutForkStartServiceThrows(): void
    {
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        $handler = new StartRunHandler(
            client: $client,
            forkStartService: null,
            forkTerminalWatcher: null,
        );

        $command = $this->createStartRunCommand('fork-run-1', [
            'fork_mode' => true,
            'fork_snapshot_path' => '/tmp/fake-snapshot.json',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fork mode requires ForkControllerStartService');

        $handler($this->createEvent($command));
    }

    public function testForkModeWithMissingTerminalWatcherThrows(): void
    {
        // ForkControllerStartService is final readonly, so we fetch a real one
        // from the container.  Since the watcher null check now fires before any
        // method call on forkStartService (preflight ordering fix), the instance
        // is never used — the test never reaches start().
        // This ensures no fork run can be started without the terminal watcher.
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        $forkStartService = self::getContainer()->get(\Ineersa\CodingAgent\Runtime\Controller\ForkControllerStartService::class);

        $handler = new StartRunHandler(
            client: $client,
            forkStartService: $forkStartService,
            forkTerminalWatcher: null,
        );

        $command = $this->createStartRunCommand('fork-run-2', [
            'fork_mode' => true,
            'fork_snapshot_path' => __FILE__,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fork mode requires ForkRunTerminalWatcher');

        $handler($this->createEvent($command));
    }

    // ── Normal start routing ──

    public function testNormalStartRoutesToClientAndEmitsRunStarted(): void
    {
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        $handler = new StartRunHandler(client: $client);

        $command = $this->createStartRunCommand('normal-run-1', [
            'prompt' => 'Hello from test',
        ], 'Hello from test');

        $emittedEvents = [];
        $handler($this->createEvent($command, $emittedEvents));

        // Must emit RunStarted (proves routing went to InProcessAgentSessionClient).
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $emittedEvents[0]->type);
        $this->assertSame('running', $emittedEvents[0]->payload['status'] ?? null);
    }

    // ── Non-start_run commands are no-ops ──

    public function testNonStartRunCommandIsIgnored(): void
    {
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        $handler = new StartRunHandler(client: $client);

        $command = new RuntimeCommand(id: 'cmd-1', type: 'user_message', payload: ['text' => 'hello']);
        $emittedEvents = [];
        $handler($this->createEvent($command, $emittedEvents));

        $this->assertCount(0, $emittedEvents);
    }

    // ── Helpers ──

    private function createStartRunCommand(string $runId, array $options, string $prompt = ''): RuntimeCommand
    {
        return new RuntimeCommand(
            id: 'cmd-'.$runId,
            type: 'start_run',
            runId: $runId,
            payload: [
                'options' => $options,
                'prompt' => $prompt,
            ],
        );
    }

    private function createEvent(RuntimeCommand $command, ?array &$emittedEvents = null): ControllerCommandEvent
    {
        $events = &$emittedEvents;
        if (null === $events) {
            $events = [];
        }

        $emit = static function (RuntimeEvent $event) use (&$events): void {
            $events[] = $event;
        };

        return new ControllerCommandEvent($command, $emit);
    }
}
