<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

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
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum as Event;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use Ineersa\Tui\Listener\TuiProjectExtensionRegistrar;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function Amp\async;
use function Amp\Socket\listen;

final class TuiProjectExtensionRegistrarTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testPublishesTheSelectedSessionFromTheInteractiveLifecycle(): void
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
            $manager = new ExtensionManager(
                config: new AppConfig(
                    tui: new TuiConfig(theme: 'default', themePaths: []),
                    logging: new LoggingConfig(),
                    extensions: new ExtensionsConfig(enabled: [Hatfield360Extension::class]),
                    cwd: $directory,
                ),
                extensionApi: $this->createStub(ExtensionApiInterface::class),
                logger: $logger,
                eventDispatcher: new EventDispatcher(),
                pathResolver: new SettingsPathResolver($directory, $directory.'/home'),
            );
            $this->assertSame([], $manager->loadExtensions());
            $registrar = new TuiProjectExtensionRegistrar($manager, $logger);
            $store = self::getContainer()->get(HatfieldSessionStore::class);
            $sessionId = $store->createSession('Original title');
            $draft = new TuiSessionState('');
            $harness = new VirtualTuiHarness(sessionId: '');
            $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($draft)->withSessionStore($store)->build();
            $registrar->register($context);
            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionDraftStarted);

            $draft->sessionId = $sessionId;
            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionStarted);
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

            $client = $this->createStub(AgentSessionClient::class);
            $draft->handle = new RunHandle($draft->sessionId);
            $job = ['owner_run_id' => 'private-child', 'job_id' => 'bash:7'];
            $child = ['child_run_id' => 'private-child', 'parent_run_id' => $draft->sessionId, 'child_sequence' => 2];
            $client->method('events')->willReturn([
                new RuntimeEvent(Event::ChildExecutionStarted->value, 'unrelated', 0, $child),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $draft->sessionId, 0, $child),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $draft->sessionId, 0, $child),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $draft->sessionId, 0, $child),
                new RuntimeEvent(Event::BackgroundJobStarted->value, 'unrelated', 0, $job),
                new RuntimeEvent(Event::BackgroundJobEnded->value, $draft->sessionId, 0, $job),
                new RuntimeEvent(Event::BackgroundJobStarted->value, $draft->sessionId, 0, $job),
                new RuntimeEvent(Event::BackgroundJobStarted->value, $draft->sessionId, 0, $job),
                new RuntimeEvent(Event::TurnStarted->value, $draft->sessionId, 1),
                new RuntimeEvent(Event::HumanInputRequested->value, $draft->sessionId, 2, ['question_id' => 'wait-1', 'approval_context' => [], 'prompt' => 'private prompt']),
                new RuntimeEvent(Event::HumanInputAnswered->value, $draft->sessionId, 3, ['question_id' => 'wait-1', 'answer' => 'private answer']),
                new RuntimeEvent(Event::ToolExecutionFailed->value, $draft->sessionId, 4),
                new RuntimeEvent(Event::RunCancelled->value, $draft->sessionId, 5),
                new RuntimeEvent(Event::BackgroundJobEnded->value, $draft->sessionId, 0, $job),
                new RuntimeEvent(Event::BackgroundJobEnded->value, $draft->sessionId, 0, $job),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $draft->sessionId, 0, ['child_run_id' => 'private-child', 'parent_run_id' => $draft->sessionId, 'child_sequence' => 12]),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $draft->sessionId, 0, [...$child, 'child_sequence' => 20]),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $draft->sessionId, 0, [...$child, 'child_sequence' => 12]),
                new RuntimeEvent(Event::ChildExecutionStarted->value, $draft->sessionId, 0, ['child_run_id' => 'nested', 'parent_run_id' => 'private-child', 'child_sequence' => 1]),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $draft->sessionId, 0, ['child_run_id' => 'nested', 'parent_run_id' => 'private-child', 'child_sequence' => 3, 'outcome' => 'cancelled']),
                new RuntimeEvent(Event::ToolExecutionFailed->value, 'private-child', 23),
                new RuntimeEvent(Event::ChildExecutionEnded->value, $draft->sessionId, 0, [...$child, 'child_sequence' => 24, 'outcome' => 'failed']),
            ]);
            $context->sessionServices->parentPoller->poll($draft, $client);
            $snapshot = $this->acceptFrame($server);
            $this->assertSame('session.snapshot', $snapshot['kind']);
            $this->assertSame($first['sessionEpoch'], $snapshot['sessionEpoch']);
            $this->assertSame('cancelled', $snapshot['turn']['status']);
            $this->assertSame('Renamed title', $snapshot['title']);

            $entity = $store->findSession($sessionId);
            $this->assertNotNull($entity);
            $entity->name = str_repeat('🦊', 1025);
            self::getContainer()->get(EntityManagerInterface::class)->flush();
            $this->assertNull($snapshot['attention']);
            $this->assertNull($snapshot['activeSubagents']);
            $this->assertNull($snapshot['backgroundScripts']);
            $this->assertArrayNotHasKey('child_run_id', $snapshot);

            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionEnded);
            $harness = new VirtualTuiHarness(sessionId: $sessionId);
            $resumed = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState(new TuiSessionState($sessionId))->withSessionStore($store)->build();
            $registrar->register($resumed);
            $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionResumed);
            $second = $this->acceptFrame($server);
            $this->assertSame($sessionId, $second['sessionId']);
            $this->assertSame(str_repeat('🦊', 1024), $second['title']);
            $this->assertNotSame($first['eventId'], $second['eventId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);

            $this->assertNull($second['activeSubagents']);
            $resumed->lifecycle->dispatchBackgroundSnapshot(new TuiBackgroundSnapshotDTO($sessionId, 1, 0));
            $reconciled = $this->acceptFrame($server);
            $this->assertSame(1, $reconciled['activeSubagents']);
            $this->assertSame($second['sessionEpoch'], $reconciled['sessionEpoch']);

            $resumed->state->sessionId = 'missing-session';
            $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionResumed);
            $missing = $this->acceptFrame($server);
            $this->assertSame('missing-session', $missing['sessionId']);
            $this->assertNull($missing['title']);

            $closing = async(static fn () => $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionGracefullyClosing));
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
            $extension = new Hatfield360Extension();
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning')->willReturnCallback(static fn () => $failed->complete());
            $extension->setLogger($logger);
            $extension->register($this->createStub(ExtensionApiInterface::class));
            $extension->onTuiSessionSelected('first');
            $failed->getFuture()->await(new TimeoutCancellation(2));

            $server = listen('unix://'.$socket);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('first', 1, TuiRunObservationTypeEnum::TurnStarted));
            if ($manual) {
                $first = $this->acceptFrame($server, '{"ok":false,"error":"unavailable"}');
                $bootstrap = $this->acceptFrame($server, json_encode(['ok' => true, 'launchId' => $launch, 'token' => $token], \JSON_THROW_ON_ERROR));
                $this->assertSame('launch.register', $bootstrap['kind']);
                $this->assertSame($first, $bootstrap);
            }
            $registration = $this->acceptFrame($server, beforeReply: static function () use ($extension): void {
                $extension->onTuiSessionSelected('second');
                $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 2, TuiRunObservationTypeEnum::TurnStarted));
            });
            $this->assertSame('session.snapshot', $registration['kind']);
            $this->assertSame('first', $registration['sessionId']);
            $latest = $this->acceptFrame($server);
            $this->assertSame('session.snapshot', $latest['kind']);
            $this->assertSame('second', $latest['sessionId']);
            $this->assertGreaterThan($registration['sequence'], $latest['sequence']);
            $this->assertGreaterThan($latest['sessionEpoch'], $latest['sequence']);
            $this->assertSame('working', $latest['turn']['status']);

            $context = $this->createMock(CommandContextInterface::class);
            $context->expects($this->once())->method('notify')->with('Hatfield 360 accepted the notification.', 'success');
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 3, TuiRunObservationTypeEnum::QuestionRequested, 'before-notice'));
            $invocation = null;
            $before = $this->acceptFrame($server, beforeReply: static function () use ($extension, $context, &$invocation): void {
                $invocation = async(static fn () => $extension->handle('Review | Available', $context));
                async(static fn () => $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 3, TuiRunObservationTypeEnum::ApprovalRequested, 'after-notice')));
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
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 4, TuiRunObservationTypeEnum::InputResolved, 'before-notice'));
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 5, TuiRunObservationTypeEnum::InputResolved, 'after-notice'));
            $this->assertNull($this->acceptFrame($server)['attention']);

            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 1, TuiRunObservationTypeEnum::ChildExecutionStarted, childRunId: 'child'));
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 2, TuiRunObservationTypeEnum::QuestionRequested, 'same-request', 'child'));
            $childWait = $this->acceptFrame($server);
            $this->assertSame('question', $childWait['attention']['reason']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 3, TuiRunObservationTypeEnum::ApprovalRequested, 'same-request'));
            $mainWait = $this->acceptFrame($server);
            $this->assertSame('permission', $mainWait['attention']['reason']);
            $this->assertNotSame($childWait['attention']['id'], $mainWait['attention']['id']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 4, TuiRunObservationTypeEnum::InputResolved, 'same-request'));
            $this->assertSame($childWait['attention'], $this->acceptFrame($server)['attention']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 5, TuiRunObservationTypeEnum::ChildExecutionEnded, childRunId: 'child'));
            $cleared = $this->acceptFrame($server);
            $this->assertNull($cleared['attention']);
            $this->assertNull($cleared['activeSubagents']);

            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 1));
            $this->assertSame(0, $this->acceptFrame($server)['activeSubagents']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 20, TuiRunObservationTypeEnum::TurnCompleted));
            $completed = $this->acceptFrame($server);
            $this->assertSame('completed', $completed['turn']['status']);
            $this->assertNull($completed['activeSubagents']);
            $this->assertSame(1, $completed['backgroundScripts']);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 1, 1));
            $working = $this->acceptFrame($server);
            $this->assertSame(1, $working['activeSubagents']);
            $this->assertSame($completed['turn'], $working['turn']);

            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 1, 1));
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('first', 0, 0));
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', null, null));
            $unavailable = $this->acceptFrame($server);
            $this->assertSame($working['sequence'] + 1, $unavailable['sequence']);
            $this->assertNull($unavailable['activeSubagents']);
            $this->assertNull($unavailable['backgroundScripts']);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 1));
            $settled = $this->acceptFrame($server);
            $this->assertSame(0, $settled['activeSubagents']);
            $this->assertSame(1, $settled['backgroundScripts']);
            $this->assertSame($completed['turn'], $settled['turn']);
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
    private function acceptFrame(ServerSocket $server, string $reply = '{"ok":true,"status":"accepted"}', ?\Closure $beforeReply = null): array
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

            return $frame;
        } finally {
            $connection->close();
        }
    }
}
