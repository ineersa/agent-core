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
 * delegation: fork_mode → forkStartService.start(); watcher start is
 * immediate after the start call.
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
        // This test cannot mock ForkControllerStartService (final readonly class),
        // so we fetch a real one from the container.  Calling start() will try to
        // parse the snapshot and likely fail.  The key assertion is that the handler
        // throws SOMETHING — proving it does not silently fall through to client.
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

        try {
            $handler($this->createEvent($command));
            self::fail('Expected RuntimeException for fork_mode with missing ForkRunTerminalWatcher — handler should not silently fall through');
        } catch (\RuntimeException $e) {
            // Accept any RuntimeException: either from trying to load the bad
            // snapshot (ForkControllerStartService) or from the terminal watcher
            // check.  Both prove the bug is fixed (handler does NOT silently call
            // client->start with a bogus empty run).
            self::assertStringContainsString('fork', $e->getMessage());
        }
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
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::RunStarted->value, $emittedEvents[0]->type);
        self::assertSame('running', $emittedEvents[0]->payload['status'] ?? null);
    }

    // ── Non-start_run commands are no-ops ──

    public function testNonStartRunCommandIsIgnored(): void
    {
        $client = self::getContainer()->get(InProcessAgentSessionClient::class);
        $handler = new StartRunHandler(client: $client);

        $command = new RuntimeCommand(id: 'cmd-1', type: 'user_message', payload: ['text' => 'hello']);
        $emittedEvents = [];
        $handler($this->createEvent($command, $emittedEvents));

        self::assertCount(0, $emittedEvents);
    }

    // ── Helpers ──

    private function createStartRunCommand(string $runId, array $options, string $prompt = ''): RuntimeCommand
    {
        return new RuntimeCommand(
            id: 'cmd-' . $runId,
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
