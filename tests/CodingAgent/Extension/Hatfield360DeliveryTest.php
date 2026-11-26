<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\DeferredFuture;
use Amp\Socket\ServerSocket;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionContext;
use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventPublisher;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum as Event;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Session\DTO\BackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionSelectedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\SessionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function Amp\async;
use function Amp\Socket\listen;

final class Hatfield360DeliveryTest extends IsolatedKernelTestCase
{
    public function testPublishesTheSelectedSessionFromLiveEvents(): void
    {
        $directory = TestDirectoryIsolation::createOsTempDir('h360');
        $endpoint = $directory.'/status.sock';
        $launchId = '44444444-4444-4444-8444-444444444444';
        $token = str_repeat('a', 64);
        $environment = [
            'H360_STATUS_ENDPOINT' => $endpoint,
            'H360_STATUS_LAUNCH_ID' => $launchId,
            'H360_STATUS_TOKEN' => $token,
        ];
        $previous = [];
        try {
            foreach ($environment as $key => $value) {
                $previous[$key] = getenv($key);
                putenv($key.'='.$value);
            }
            $server = listen('unix://'.$endpoint);
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->never())->method('warning');
            $dispatcher = new EventDispatcher();
            $events = new ExtensionSessionEventPublisher($dispatcher, $logger);
            $store = self::getContainer()->get(HatfieldSessionStore::class);
            $session = new ExtensionSessionContext($events, $store, self::getContainer()->get(BackgroundWorkSnapshotProvider::class), $logger);
            $api = $this->createStub(ExtensionApiInterface::class);
            $api->method('session')->willReturn($session);
            $manager = new ExtensionManager(
                config: new AppConfig(
                    tui: new TuiConfig(theme: 'default', themePaths: []),
                    logging: new LoggingConfig(),
                    extensions: new ExtensionsConfig(enabled: [Hatfield360Extension::class]),
                    cwd: $directory,
                ),
                extensionApi: $api,
                logger: $logger,
                eventDispatcher: $dispatcher,
                pathResolver: new SettingsPathResolver($directory, $directory.'/home'),
            );
            $this->assertSame([], $manager->loadExtensions());
            $sessionId = $store->createSession('Original title');

            $events->select($sessionId, false);
            $first = $this->acceptFrame($server);
            $this->assertSame($sessionId, $first['sessionId']);
            $this->assertSame('Original title', $first['title']);

            $store->updateMetadata($sessionId, ['name' => 'Renamed title']);
            $this->assertSame($launchId, $first['launchId']);
            $this->assertSame($token, $first['token']);
            $this->assertSame(1, $first['version']);
            $this->assertSame('session.snapshot', $first['kind']);
            $this->assertSame('hatfield', $first['provider']);
            $this->assertNull($first['turn']);
            $this->assertSame($first['sequence'], $first['sessionEpoch']);

            $job = ['owner_run_id' => 'private-child', 'job_id' => 'bash:7'];
            $child = ['child_run_id' => 'private-child', 'parent_run_id' => $sessionId, 'child_sequence' => 2];
            foreach ([
                new RuntimeEvent(Event::ChildExecutionStarted->value, 'unrelated', 0, $child),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $sessionId, 0, $child),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $sessionId, 0, $child),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $sessionId, 0, $child),
                new RuntimeEvent(Event::BackgroundJobStarted->value, 'unrelated', 0, $job),
                new RuntimeEvent(Event::BackgroundJobEnded->value, $sessionId, 0, $job),
                new RuntimeEvent(Event::BackgroundJobStarted->value, $sessionId, 0, $job),
                new RuntimeEvent(Event::TurnStarted->value, $sessionId, 1),
                new RuntimeEvent(Event::HumanInputRequested->value, $sessionId, 2, ['question_id' => 'wait-1', 'approval_context' => [], 'prompt' => 'private prompt']),
                new RuntimeEvent(Event::HumanInputAnswered->value, $sessionId, 3, ['question_id' => 'wait-1', 'answer' => 'private answer']),
                new RuntimeEvent(Event::AssistantMessageCompleted->value, $sessionId, 4, ['text' => 'Private final answer']),
                new RuntimeEvent(Event::ToolExecutionFailed->value, $sessionId, 5),
                new RuntimeEvent(Event::RunCancelled->value, $sessionId, 6),
                new RuntimeEvent(Event::BackgroundJobEnded->value, $sessionId, 0, $job),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $sessionId, 0, [...$child, 'child_sequence' => 12]),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $sessionId, 0, [...$child, 'child_sequence' => 20]),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $sessionId, 0, [...$child, 'child_sequence' => 12]),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $sessionId, 0, ['child_run_id' => 'nested', 'parent_run_id' => 'private-child', 'child_sequence' => 1]),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $sessionId, 0, ['child_run_id' => 'nested', 'parent_run_id' => 'private-child', 'child_sequence' => 3, 'outcome' => 'cancelled']),
                new RuntimeEvent(Event::ToolExecutionFailed->value, 'private-child', 23),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $sessionId, 0, [...$child, 'child_sequence' => 24, 'outcome' => 'failed']),
            ] as $event) {
                $events->publish($event);
            }
            $snapshot = $this->acceptFrame($server);
            $this->assertSame('session.snapshot', $snapshot['kind']);
            $this->assertSame($first['sessionEpoch'], $snapshot['sessionEpoch']);
            $this->assertSame(['id' => '1', 'status' => 'cancelled'], $snapshot['turn'], 'Only a completed turn carries the response.');
            $this->assertSame('Renamed title', $snapshot['title']);

            $entity = $store->findSession($sessionId);
            $this->assertNotNull($entity);
            $entity->name = str_repeat('🦊', 1025);
            self::getContainer()->get(EntityManagerInterface::class)->flush();
            $this->assertNull($snapshot['attention']);
            $this->assertSame(0, $snapshot['activeSubagents']);
            $this->assertSame(0, $snapshot['backgroundScripts']);
            $this->assertArrayNotHasKey('child_run_id', $snapshot);

            // A switch or reload shuts the client down before the next selection.
            $events->clear();
            $events->select($sessionId, true);
            $second = $this->acceptFrame($server);
            $this->assertSame($sessionId, $second['sessionId']);
            $this->assertSame(str_repeat('🦊', 1024), $second['title']);
            $this->assertNotSame($first['eventId'], $second['eventId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);
            $this->assertNull($second['turn']);
            $this->assertNull($second['attention']);
            $this->assertSame(0, $second['activeSubagents']);

            $events->clear();
            $events->select('missing-session', true);
            $missing = $this->acceptFrame($server);
            $this->assertSame('missing-session', $missing['sessionId']);
            $this->assertNull($missing['title']);

            $closing = async(static fn () => $events->close());
            $end = $this->acceptFrame($server);
            $closing->await(new TimeoutCancellation(2));
            $this->assertSame('session.ended', $end['kind']);
            $this->assertSame($missing['sessionId'], $end['sessionId']);
            $this->assertSame($missing['sessionEpoch'], $end['sessionEpoch']);
            $this->assertSame($missing['sequence'] + 1, $end['sequence']);
        } finally {
            if (isset($server)) {
                $server->close();
            }
            foreach ($previous as $key => $value) {
                putenv(false === $value ? $key : $key.'='.$value);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function credentials(): iterable
    {
        yield 'explicit' => [false];
        yield 'manual' => [true];
    }

    #[DataProvider('credentials')]
    public function testSendsLatestSnapshotAfterUnavailableSocket(bool $manual): void
    {
        if ($manual && 'Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Manual launch identity requires Linux.');
        }
        $directory = TestDirectoryIsolation::createOsTempDir('h360');
        $socket = $directory.'/s';
        $launch = '44444444-4444-4444-8444-444444444444';
        $token = str_repeat('a', 64);
        $environment = ['H360_STATUS_ENDPOINT' => $socket, 'H360_STATUS_LAUNCH_ID' => $manual ? false : $launch,
            'H360_STATUS_TOKEN' => $manual ? false : $token, 'H360_STATUS_BOOTSTRAP_TOKEN' => str_repeat('b', 64)];
        $previous = [];
        try {
            foreach ($environment as $key => $value) {
                $previous[$key] = getenv($key);
                putenv(false === $value ? $key : $key.'='.$value);
            }
            $failed = new DeferredFuture();
            $settlementFailed = new DeferredFuture();
            $clock = new MockClock();
            $extension = new Hatfield360Extension($clock);
            $drain = new \ReflectionProperty($extension, 'drain');
            $selection = 'first';
            $children = $scripts = null;
            $session = $this->createStub(SessionContextInterface::class);
            $session->method('getSessionId')->willReturnCallback(static function () use (&$selection): string { return $selection; });
            $session->method('getBackgroundSnapshot')->willReturnCallback(static function () use (&$selection, &$children, &$scripts): BackgroundSnapshotDTO {
                return new BackgroundSnapshotDTO($selection, $children, $scripts);
            });
            $api = $this->createStub(ExtensionApiInterface::class);
            $api->method('session')->willReturn($session);
            $tick = null;
            $ui = $this->createStub(TuiExtensionContextInterface::class);
            $ui->method('onTick')->willReturnCallback(static function (\Closure $listener) use (&$tick): void {
                $tick = $listener;
            });
            // Each sample is one throttled idle-tick poll.
            $sample = static function (?int $agents, ?int $jobs) use (&$tick, $clock, &$children, &$scripts): void {
                $children = $agents;
                $scripts = $jobs;
                $clock->modify('+500 milliseconds');
                $tick();
            };
            $logger = $this->createMock(LoggerInterface::class);
            $drops = [];
            $logger->expects($this->exactly(4))->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$drops, $failed, $settlementFailed): void {
                $drops[] = $context['session_id'];
                if (1 === \count($drops)) {
                    $failed->complete();
                } elseif (3 === \count($drops)) {
                    $settlementFailed->complete();
                }
            });
            $extension->setLogger($logger);
            $extension->register($api);
            $extension->registerTui($ui);
            $extension->onSessionSelected(new SessionSelectedEvent('first', false));
            $failed->getFuture()->await(new TimeoutCancellation(2));

            $server = listen('unix://'.$socket);
            $extension->onTurnStarted(new TurnStartedEvent('first', 1, null));
            if ($manual) {
                $first = $this->acceptFrame($server, '{"ok":false,"error":"unavailable"}');
                $bootstrap = $this->acceptFrame($server, json_encode(['ok' => true, 'launchId' => $launch, 'token' => $token], \JSON_THROW_ON_ERROR));
                $this->assertSame('launch.register', $bootstrap['kind']);
                $this->assertSame($first, $bootstrap);
            }
            $registration = $this->acceptFrame($server, '{"ok":true,"status":"stale"}', beforeReply: static function () use ($extension, &$selection): void {
                $extension->onSessionSelected(new SessionSelectedEvent($selection = 'second', false));
                $extension->onTurnStarted(new TurnStartedEvent('second', 2, null));
            });
            $this->assertSame('session.snapshot', $registration['kind']);
            $this->assertSame('first', $registration['sessionId']);
            $this->assertSame('1', $registration['turn']['id'], 'Live response identity is the canonical opening sequence.');
            $latest = $this->acceptFrame($server, waitForPeerClose: true);
            $this->assertSame('session.snapshot', $latest['kind']);
            $this->assertSame('second', $latest['sessionId']);
            $this->assertGreaterThan($registration['sequence'], $latest['sequence']);
            $this->assertGreaterThan($latest['sessionEpoch'], $latest['sequence']);
            $this->assertSame('working', $latest['turn']['status']);
            $this->assertSame('2', $latest['turn']['id']);

            $context = $this->createMock(CommandContextInterface::class);
            $context->expects($this->exactly(6))->method('notify')->with('Hatfield 360 accepted the notification.', 'success');
            $assertNoDuplicate = function (?int $children, ?int $scripts) use ($sample, $extension, $context, $server): void {
                $sample($children, $scripts);
                // The notice is an ordering barrier: any duplicate would arrive first.
                $barrier = async(static fn () => $extension->handle('Barrier | Check snapshot order', $context));
                $this->assertSame('notification', $this->acceptFrame($server)['kind']);
                $barrier->await(new TimeoutCancellation(2));
            };
            $assertNoDuplicate(null, null);
            $sample(1, 0);
            $refused = $this->acceptFrame($server, '{"ok":true,"status":"stale"}', beforeReply: static function () use ($sample): void {
                $sample(2, 0);
            });
            $newer = $this->acceptFrame($server, waitForPeerClose: true);
            $this->assertGreaterThan($refused['sequence'], $newer['sequence']);
            $this->assertSame($latest['sessionEpoch'], $newer['sessionEpoch']);
            $this->assertSame(2, $newer['activeSubagents']);
            $assertNoDuplicate(2, 0);
            $extension->onInputRequested(new InputRequestedEvent('second', 3, 'before-notice', InputRequestTypeEnum::Question));
            $invocation = null;
            $before = $this->acceptFrame($server, beforeReply: static function () use ($extension, $context, &$invocation): void {
                $invocation = async(static fn () => $extension->handle('Review | Available', $context));
                async(static fn () => $extension->onInputRequested(new InputRequestedEvent('second', 3, 'after-notice', InputRequestTypeEnum::Approval)));
            });
            $notice = $this->acceptFrame($server);
            $after = $this->acceptFrame($server);
            $this->assertNotNull($invocation);
            $invocation->await(new TimeoutCancellation(2));
            $this->assertSame('notification', $notice['kind']);
            $this->assertSame('Review', $notice['title']);
            $this->assertSame('Available', $notice['body']);
            $this->assertSame($latest['sessionEpoch'], $notice['sessionEpoch']);
            $this->assertGreaterThan($before['sequence'], $notice['sequence']);
            $this->assertGreaterThan($notice['sequence'], $after['sequence']);
            $extension->onInputResolved(new InputResolvedEvent('second', 4, 'before-notice'));
            $extension->onInputResolved(new InputResolvedEvent('second', 5, 'after-notice'));
            $this->assertNull($this->acceptFrame($server)['attention']);

            $extension->onChildExecutionStarted(new ChildExecutionStartedEvent('second', 1, 'child'));
            $extension->onInputRequested(new InputRequestedEvent('second', 2, 'same-request', InputRequestTypeEnum::Question, 'child'));
            $childWait = $this->acceptFrame($server);
            $this->assertSame('question', $childWait['attention']['reason']);
            $extension->onInputRequested(new InputRequestedEvent('second', 3, 'same-request', InputRequestTypeEnum::Approval));
            $mainWait = $this->acceptFrame($server);
            $this->assertSame('permission', $mainWait['attention']['reason']);
            $this->assertNotSame($childWait['attention']['id'], $mainWait['attention']['id']);
            // An internal start does not split the live response or drop attention.
            $extension->onTurnStarted(new TurnStartedEvent('second', 5, null));
            $assertNoDuplicate(2, 0);
            $extension->onInputResolved(new InputResolvedEvent('second', 6, 'same-request'));
            $this->assertSame($childWait['attention'], $this->acceptFrame($server)['attention']);
            $extension->onChildExecutionEnded(new ChildExecutionEndedEvent('second', 5, 'child'));
            $cleared = $this->acceptFrame($server);
            $this->assertNull($cleared['attention']);
            $this->assertSame(2, $cleared['activeSubagents']);

            $sample(0, 1);
            $this->assertSame(0, $this->acceptFrame($server)['activeSubagents']);
            $children = null; // The direct refresh is unavailable, despite the previous zero sample.
            $extension->onTurnCompleted(new TurnCompletedEvent('second', 20));
            $completed = $this->acceptFrame($server);
            $this->assertSame('completed', $completed['turn']['status']);
            $this->assertSame('2', $completed['turn']['id'], 'Live completion settles the observed response.');
            $this->assertNull($completed['activeSubagents']);
            $this->assertSame(1, $completed['backgroundScripts']);
            $sample(1, 1);
            $working = $this->acceptFrame($server);
            $this->assertSame(1, $working['activeSubagents']);
            $this->assertSame($completed['turn'], $working['turn']);

            $sample(1, 1);
            $sample(null, null);
            $unavailable = $this->acceptFrame($server);
            $this->assertSame($working['sequence'] + 1, $unavailable['sequence']);
            $this->assertNull($unavailable['activeSubagents']);
            $this->assertNull($unavailable['backgroundScripts']);
            $sample(0, 1);
            $pending = $this->acceptFrame($server);
            $this->assertSame($completed['turn'], $pending['turn']);
            $this->assertNull($pending['activeSubagents']);
            $assertNoDuplicate(0, 1);
            $clock->modify('+5 seconds');
            $sample(0, 1);
            $unconfirmed = $this->acceptFrame($server, '{"ok":true,"status":"stale"}');
            $this->assertSame(0, $unconfirmed['activeSubagents']);
            $settlementFailed->getFuture()->await(new TimeoutCancellation(2));
            $this->assertSame(['first', 'second', 'second'], $drops);
            $drain->getValue($extension)?->await(new TimeoutCancellation(2));
            $assertNoDuplicate(0, 1);
            $clock->modify('+1 second');
            $sample(0, 1);
            $attempt = $drain->getValue($extension);
            $retried = $this->acceptFrame($server, '{"ok":true,"status":"stale"}');
            $attempt->await(new TimeoutCancellation(2));
            $sample(0, 1);
            $this->assertNull($drain->getValue($extension), 'The next poll is too early.');
            $clock->modify('+2 seconds');
            $sample(0, 1);
            $attempt = $drain->getValue($extension);
            $settled = $this->acceptFrame($server);
            $attempt->await(new TimeoutCancellation(2));
            $clock->modify('+30 seconds');
            $sample(0, 1);
            $this->assertNull($drain->getValue($extension), 'Confirmation ends periodic retries.');
            $this->assertGreaterThan($retried['sequence'], $settled['sequence']);
            $this->assertGreaterThan($unconfirmed['sequence'], $settled['sequence']);
            $this->assertNotSame($unconfirmed['eventId'], $settled['eventId']);
            $this->assertSame($unconfirmed['sessionEpoch'], $settled['sessionEpoch']);
            $this->assertSame(0, $settled['activeSubagents']);
            $this->assertSame(1, $settled['backgroundScripts']);
            $this->assertSame($completed['turn'], $settled['turn']);

            // A resumed child can outlive the parent's interim response.
            $extension->onTurnStarted(new TurnStartedEvent('second', 21, null));
            $resumedParent = $this->acceptFrame($server);
            $sample(1, 1);
            $this->assertSame($resumedParent['turn'], $this->acceptFrame($server)['turn']);
            $extension->onTurnCompleted(new TurnCompletedEvent('second', 22));
            $interim = $this->acceptFrame($server);
            $this->assertSame(['id' => $resumedParent['turn']['id'], 'status' => 'completed'], $interim['turn']);
            $this->assertSame(1, $interim['activeSubagents']);
            $sample(0, 1);
            $afterChild = $this->acceptFrame($server);
            $this->assertSame($interim['turn'], $afterChild['turn']);
            $this->assertNull($afterChild['activeSubagents']);

            $clock->modify('+5 seconds');
            $sample(0, 2);
            $attempt = $drain->getValue($extension);
            $refusedCompletion = $this->acceptFrame($server, '{"ok":true,"status":"stale"}');
            $this->assertSame(0, $refusedCompletion['activeSubagents']);
            $attempt->await(new TimeoutCancellation(2));

            $children = 1;
            $extension->onChildExecutionStarted(new ChildExecutionStartedEvent('second', 1, 'resumed-child'));
            $this->assertSame(1, $this->acceptFrame($server)['activeSubagents']);
            $children = 0;
            $extension->onChildExecutionEnded(new ChildExecutionEndedEvent('second', 2, 'resumed-child'));
            $attempt = $drain->getValue($extension);
            $pendingAgain = $this->acceptFrame($server);
            $this->assertSame($refusedCompletion['turn'], $pendingAgain['turn']);
            $this->assertNull($pendingAgain['activeSubagents'], 'Child settlement cannot deliver the refused completion before reconfirmation.');
            $attempt->await(new TimeoutCancellation(2));
            $extension->onSessionSelected(new SessionSelectedEvent($selection = 'third', false));
            $attempt = $drain->getValue($extension);
            $this->assertSame('third', $this->acceptFrame($server)['sessionId']);
            $attempt->await(new TimeoutCancellation(2));
            $clock->modify('+30 seconds');
            $sample(0, 2);
            $this->assertNull($drain->getValue($extension), 'A past selection cannot release a pending completion.');
        } finally {
            if (isset($server)) {
                $server->close();
            }
            foreach ($previous as $key => $value) {
                putenv(false === $value ? $key : $key.'='.$value);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @return array<string, mixed> */
    private function acceptFrame(ServerSocket $server, string $reply = '{"ok":true,"status":"accepted"}', ?\Closure $beforeReply = null, bool $waitForPeerClose = false): array
    {
        $deadline = new TimeoutCancellation(2);
        $connection = $server->accept($deadline);
        $this->assertNotNull($connection);
        try {
            $line = '';
            while (!str_contains($line, "\n")) {
                $chunk = $connection->read($deadline);
                $this->assertNotNull($chunk);
                $line .= $chunk;
            }
            $frame = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $this->assertIsArray($frame);
            $beforeReply?->__invoke();
            $connection->write($reply."\n");
            if ($waitForPeerClose) {
                $this->assertNull($connection->read($deadline));
            }

            return $frame;
        } finally {
            $connection->close();
        }
    }
}
