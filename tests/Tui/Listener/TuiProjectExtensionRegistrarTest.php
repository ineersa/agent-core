<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Amp\DeferredFuture;
use Amp\Socket\ServerSocket;
use Amp\TimeoutCancellation;
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
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use Ineersa\Tui\Listener\TuiProjectExtensionRegistrar;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function Amp\async;
use function Amp\Socket\listen;

final class TuiProjectExtensionRegistrarTest extends TestCase
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
            $draft = new TuiSessionState('');
            $harness = new VirtualTuiHarness(sessionId: '');
            $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($draft)->build();
            $registrar->register($context);
            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionDraftStarted);

            $draft->sessionId = 'promoted-session';
            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionStarted);
            $first = $this->acceptFrame($server);
            $this->assertSame('promoted-session', $first['sessionId']);
            $this->assertSame($launchId, $first['launchId']);
            $this->assertSame($token, $first['token']);
            $this->assertSame(1, $first['version']);
            $this->assertSame('session.started', $first['kind']);
            $this->assertSame('hatfield', $first['provider']);
            $this->assertSame(['waits', 'session-end', 'scripts'], $first['capabilities']);
            $this->assertSame($first['sequence'], $first['sessionEpoch']);

            $client = $this->createStub(AgentSessionClient::class);
            $draft->handle = new RunHandle($draft->sessionId);
            $job = ['owner_run_id' => 'private-child', 'job_id' => 'bash:7'];
            $client->method('events')->willReturn([
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
            ]);
            $context->sessionServices->parentPoller->poll($draft, $client);
            $scriptStart = $this->acceptFrame($server);
            $this->assertSame('script.started', $scriptStart['kind']);
            $this->assertSame(hash('sha256', "private-child\0bash:7"), $scriptStart['scriptId']);
            $this->assertSame($first['sessionEpoch'], $scriptStart['sessionEpoch']);
            $this->assertArrayNotHasKey('owner_run_id', $scriptStart);
            $frames = [];
            for ($i = 0; $i < 5; ++$i) {
                $frames[] = $this->acceptFrame($server);
            }
            $this->assertSame(['turn.started', 'activity.changed', 'wait.started', 'wait.resolved', 'turn.ended'], array_column($frames, 'kind'));
            $this->assertSame('working', $frames[1]['activity']);
            $this->assertSame('permission', $frames[2]['reason']);
            $this->assertSame($frames[2]['sequence'], $frames[3]['waitStart']);
            $this->assertSame($frames[0]['sequence'], $frames[4]['turnStart']);
            $this->assertSame('cancelled', $frames[4]['outcome']);
            $this->assertArrayNotHasKey('prompt', $frames[2]);
            $this->assertArrayNotHasKey('answer', $frames[3]);
            $scriptEnd = $this->acceptFrame($server);
            $this->assertSame('script.ended', $scriptEnd['kind']);
            $this->assertSame($scriptStart['scriptId'], $scriptEnd['scriptId']);
            $this->assertSame($scriptStart['sequence'], $scriptEnd['scriptStart']);
            $this->assertSame($scriptStart['sessionEpoch'], $scriptEnd['sessionEpoch']);
            $this->assertSame($frames[4]['sequence'] + 1, $scriptEnd['sequence']);
            $this->assertArrayNotHasKey('outcome', $scriptEnd);

            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionEnded);
            $harness = new VirtualTuiHarness(sessionId: 'resumed-session');
            $resumed = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState(new TuiSessionState('resumed-session'))->build();
            $registrar->register($resumed);
            $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionResumed);
            $second = $this->acceptFrame($server);
            $this->assertSame('resumed-session', $second['sessionId']);
            $this->assertNotSame($first['eventId'], $second['eventId']);
            $this->assertGreaterThan($first['sequence'], $second['sequence']);
            $this->assertSame($second['sequence'], $second['sessionEpoch']);

            $closing = async(static fn () => $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionGracefullyClosing));
            $end = $this->acceptFrame($server);
            $closing->await(new TimeoutCancellation(2));
            $this->assertSame('session.ended', $end['kind']);
            $this->assertSame($second['sessionId'], $end['sessionId']);
            $this->assertSame($second['sessionEpoch'], $end['sessionEpoch']);
            $this->assertSame($second['sequence'] + 1, $end['sequence']);
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
    public function testRecoversLatestCheckpointAfterUnavailableSocket(bool $manual): void
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
            $this->assertSame('session.started', $registration['kind']);
            $this->assertSame('first', $registration['sessionId']);
            $latest = $this->acceptFrame($server);
            $this->assertSame('session.started', $latest['kind']);
            $this->assertSame('second', $latest['sessionId']);
            $this->assertGreaterThan($registration['sequence'], $latest['sequence']);
            $turn = $this->acceptFrame($server);
            $this->assertSame('turn.started', $turn['kind']);
            $this->assertSame($latest['sessionEpoch'], $turn['actorStart']);
            $activity = $this->acceptFrame($server);
            $this->assertSame('activity.changed', $activity['kind']);
            $this->assertSame($turn['sequence'], $activity['turnStart']);
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
