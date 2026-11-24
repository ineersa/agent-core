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
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiForegroundBaselineDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiForegroundRequestDTO;
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

            // The registered optional observer receives only the accepted ready
            // baseline; real spool frames pass through the production poller.
            $spools = self::getContainer()->get(SessionBootstrapSpoolStore::class);
            $foreground = ['current_foreground_response_seq' => 7, 'pending_foreground_requests' => [['request_id' => 'resumed-question', 'category' => 'approval']]];
            $resume = array_replace((new SessionResumeMetadataProjection())->toArray(), $foreground,
                ['status' => 'waiting_human', 'model' => null, 'turn_no' => 3]);
            $cut = $spools->seal($sessionId, 40, 500, 0, [], $resume);
            $events = [new RuntimeEvent(Event::BootstrapAvailable->value, $sessionId, 0, $cut->toArray() + ['command_id' => 'resume-ready']),
                ...iterator_to_array(self::getContainer()->get(SessionBootstrapTransfer::class)->frames($cut))];
            $attached = $this->createMock(AgentSessionClient::class);
            $attached->method('events')->willReturnCallback(static function () use (&$events): array { return $events; });
            $attached->expects($this->once())->method('acknowledgeBootstrap')->with($cut->toArray())->willReturnCallback(static function () use ($spools, $cut): void { $spools->acknowledge($cut); });
            $resumed->state->handle = new RunHandle($sessionId, 'bootstrapping', 'resume-ready');
            $resumed->state->beginBootstrapAttach();
            $poll = static function () use ($resumed, $attached): void {
                $resumed->state->lastPoll = 0;
                $resumed->sessionServices->parentPoller->poll($resumed->state, $attached,
                    onBootstrapMounted: static fn () => $resumed->screen->setTranscriptBlocks($resumed->state->transcript));
            };
            $poll();
            $this->assertFalse($resumed->state->sessionReady);
            $events = [new RuntimeEvent(Event::SessionReady->value, $sessionId, 0, $cut->toArray() + ['foreground' => $foreground])];
            $poll();
            $baseline = $this->acceptFrame($server);
            $this->assertSame(['id' => '7', 'status' => 'working'], $baseline['turn']);
            $this->assertSame('permission', $baseline['attention']['reason']);
            $this->assertSame(1, $baseline['activeSubagents']);
            $events = [new RuntimeEvent(Event::HumanInputAnswered->value, $sessionId, 41, ['question_id' => 'resumed-question'])];
            $poll();
            $this->assertNull($this->acceptFrame($server)['attention']);
            $events = [new RuntimeEvent(Event::RunCompleted->value, $sessionId, 42)];
            $poll();
            $this->assertSame(['id' => '7', 'status' => 'completed'], $this->acceptFrame($server)['turn']);

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
            $settlementFailed = new DeferredFuture();
            $extension = new Hatfield360Extension();
            $logger = $this->createMock(LoggerInterface::class);
            $drops = [];
            $logger->expects($this->exactly(3))->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$drops, $failed, $settlementFailed): void {
                $drops[] = $context['session_id'];
                if (1 === \count($drops)) {
                    $failed->complete();
                } elseif (3 === \count($drops)) {
                    $settlementFailed->complete();
                }
            });
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
            $registration = $this->acceptFrame($server, '{"ok":true,"status":"stale"}', beforeReply: static function () use ($extension): void {
                $extension->onTuiSessionSelected('second');
                $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('second', 2, 2, []));
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
            $context->expects($this->exactly(5))->method('notify')->with('Hatfield 360 accepted the notification.', 'success');
            $assertNoDuplicate = function (?int $children, ?int $scripts) use ($extension, $context, $server): void {
                $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', $children, $scripts));
                // The notice is an ordering barrier: any duplicate would arrive first.
                $barrier = async(static fn () => $extension->handle('Barrier | Check snapshot order', $context));
                $this->assertSame('notification', $this->acceptFrame($server)['kind']);
                $barrier->await(new TimeoutCancellation(2));
            };
            $assertNoDuplicate(null, null);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 1, 0));
            $refused = $this->acceptFrame($server, '{"ok":true,"status":"stale"}', beforeReply: static function () use ($extension): void {
                $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 2, 0));
            });
            $newer = $this->acceptFrame($server, waitForPeerClose: true);
            $this->assertGreaterThan($refused['sequence'], $newer['sequence']);
            $this->assertSame($latest['sessionEpoch'], $newer['sessionEpoch']);
            $this->assertSame(2, $newer['activeSubagents']);
            $assertNoDuplicate(2, 0);
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
            // A same-response baseline and internal start preserve both identities,
            // without another snapshot ahead of the explicit ordering barrier.
            $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('second', 4, 2,
                [new TuiForegroundRequestDTO('same-request', TuiRunObservationTypeEnum::ApprovalRequested)]));
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 5, TuiRunObservationTypeEnum::TurnStarted));
            $assertNoDuplicate(2, 0);
            $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('second', 6, 2, []));
            $this->assertSame($childWait['attention'], $this->acceptFrame($server)['attention']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 5, TuiRunObservationTypeEnum::ChildExecutionEnded, childRunId: 'child'));
            $cleared = $this->acceptFrame($server);
            $this->assertNull($cleared['attention']);
            $this->assertSame(2, $cleared['activeSubagents']);

            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 1));
            $this->assertSame(0, $this->acceptFrame($server)['activeSubagents']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 20, TuiRunObservationTypeEnum::TurnCompleted));
            $completed = $this->acceptFrame($server);
            $this->assertSame('completed', $completed['turn']['status']);
            $this->assertSame('2', $completed['turn']['id'], 'Live completion settles the restored canonical response.');
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
            $unconfirmed = $this->acceptFrame($server, '{"ok":true,"status":"stale"}');
            $settlementFailed->getFuture()->await(new TimeoutCancellation(2));
            $this->assertSame(['first', 'second', 'second'], $drops);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 1));
            $exhausted = $this->acceptFrame($server, '{"ok":true,"status":"stale"}', waitForPeerClose: true);
            $assertNoDuplicate(0, 1);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 0));
            $this->acceptFrame($server, '{"ok":true,"status":"stale"}', waitForPeerClose: true);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 0));
            $settled = $this->acceptFrame($server);
            $this->assertGreaterThan($exhausted['sequence'], $settled['sequence']);
            $this->assertGreaterThan($unconfirmed['sequence'], $settled['sequence']);
            $this->assertNotSame($unconfirmed['eventId'], $settled['eventId']);
            $this->assertSame($unconfirmed['sessionEpoch'], $settled['sessionEpoch']);
            $this->assertSame(0, $settled['activeSubagents']);
            $this->assertSame(0, $settled['backgroundScripts']);
            $this->assertSame($completed['turn'], $settled['turn']);

            // A resumed child can outlive the parent's interim response.
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 21, TuiRunObservationTypeEnum::TurnStarted));
            $resumedParent = $this->acceptFrame($server);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 1, 1));
            $this->assertSame($resumedParent['turn'], $this->acceptFrame($server)['turn']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 22, TuiRunObservationTypeEnum::TurnCompleted));
            $interim = $this->acceptFrame($server);
            $this->assertSame(['id' => $resumedParent['turn']['id'], 'status' => 'completed'], $interim['turn']);
            $this->assertSame(1, $interim['activeSubagents']);
            $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('second', 0, 1));
            $afterChild = $this->acceptFrame($server);
            $this->assertSame($interim['turn'], $afterChild['turn']);
            $this->assertSame(0, $afterChild['activeSubagents']);

            // Unknown response identity stays unknown while current input can
            // still be reconciled and resolved. No historical terminal is sent.
            $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('second', 24, null,
                [new TuiForegroundRequestDTO('attached-question', TuiRunObservationTypeEnum::QuestionRequested)]));
            $unknown = $this->acceptFrame($server);
            $this->assertNull($unknown['turn']);
            $this->assertSame('question', $unknown['attention']['reason']);
            $extension->onTuiRunObserved(new TuiRunObservationDTO('second', 25, TuiRunObservationTypeEnum::InputResolved, 'attached-question'));
            $resolved = $this->acceptFrame($server);
            $this->assertNull($resolved['attention']);
            $this->assertNull($resolved['turn']);
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
