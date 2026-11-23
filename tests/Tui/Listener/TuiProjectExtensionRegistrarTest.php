<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Amp\Socket\ServerSocket;
use Amp\TimeoutCancellation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Builtin\Hatfield360\Hatfield360Extension;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Tui\Listener\TuiProjectExtensionRegistrar;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
            $logger = new TestLogger();
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
            EventLoop::run();
            $this->assertSame([], $logger->records);

            $draft->sessionId = 'promoted-session';
            $context->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionStarted);
            $first = $this->acceptFrame($server);
            $this->assertSame('promoted-session', $first['sessionId']);
            $this->assertSame($launchId, $first['launchId']);
            $this->assertSame($token, $first['token']);
            $this->assertSame(1, $first['version']);
            $this->assertSame('session.started', $first['kind']);
            $this->assertSame('hatfield', $first['provider']);
            $this->assertSame([], $first['capabilities']);
            $this->assertSame($first['sequence'], $first['sessionEpoch']);

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
            EventLoop::run();
            $this->assertSame([], $logger->records);

            $server->close();
            $resumed->lifecycle->dispatch(TuiSessionLifecycleEventTypeEnum::SessionStarted);
            EventLoop::run();
            $this->assertCount(1, $logger->records);
            $this->assertSame('hatfield_360.session_registration_failed', $logger->records[0]['message']);
            $this->assertArrayNotHasKey('token', $logger->records[0]['context']);
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
    private function acceptFrame(ServerSocket $server): array
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
            $connection->write("{\"ok\":true,\"status\":\"accepted\"}\n");

            return $frame;
        } finally {
            $connection->close();
        }
    }
}
