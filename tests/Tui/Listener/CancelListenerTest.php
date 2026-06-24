<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\EventListener\RuntimeExceptionPolicySubscriber;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeErrorCaptureConfig;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\CancelListener;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

class CancelListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    private TuiSessionState $state;
    /** @var AgentSessionClient&MockObject */
    private AgentSessionClient $client;
    private LoggerInterface $logger;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->state = new TuiSessionState('test-session');
        $this->client = $this->createMock(AgentSessionClient::class);
        $this->logger = new NullLogger();

        $this->tmpDir = sys_get_temp_dir().'/hatfield-cancel-test-'.uniqid('', true);
        mkdir($this->tmpDir.'/.hatfield/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // ── Active run cancellation ──────────────────────────────────

    #[Test]
    public function cancelActiveRunSendsCancelToClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->handle = new RunHandle('run-123');

        $this->client->expects($this->once())
            ->method('cancel')
            ->with('run-123');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelActiveRunTransitionsToCancelling(): void
    {
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->handle = new RunHandle('run-123');

        $this->client->expects($this->once())
            ->method('cancel');
        $this->dispatchCancelEvent();

        $this->assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
    }

    #[Test]
    public function cancelStartingRunSendsCancel(): void
    {
        $this->state->activity = RunActivityStateEnum::Starting;
        $this->state->handle = new RunHandle('run-456');

        $this->client->expects($this->once())
            ->method('cancel')
            ->with('run-456');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelWaitingHumanRunSendsCancel(): void
    {
        $this->state->activity = RunActivityStateEnum::WaitingHuman;
        $this->state->handle = new RunHandle('run-789');

        $this->client->expects($this->once())
            ->method('cancel')
            ->with('run-789');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelAlreadyCancellingStillSendsCancel(): void
    {
        $this->state->activity = RunActivityStateEnum::Cancelling;
        $this->state->handle = new RunHandle('run-cxl');

        $this->client->expects($this->once())
            ->method('cancel')
            ->with('run-cxl');

        $this->dispatchCancelEvent();
    }

    // ── Idle/terminal — no cancel sent ──────────────────────────

    #[Test]
    public function cancelIdleDoesNotCallClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Idle;
        $this->state->handle = new RunHandle('run-idle');

        $this->client->expects($this->never())
            ->method('cancel');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelCompletedDoesNotCallClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Completed;
        $this->state->handle = new RunHandle('run-done');

        $this->client->expects($this->never())
            ->method('cancel');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelFailedWithoutRuntimePollErrorDoesNotCallClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Failed;
        $this->state->handle = new RunHandle('run-fail');
        $this->state->lastRuntimePollError = '';

        $this->client->expects($this->never())
            ->method('cancel');

        $this->dispatchCancelEvent();
    }

    #[Test]
    public function cancelFailedWithRuntimePollErrorSendsCancelAndTransitionsToCancelling(): void
    {
        $this->state->activity = RunActivityStateEnum::Failed;
        $this->state->handle = new RunHandle('run-fail');
        $this->state->lastRuntimePollError = 'Controller process has crashed too many times (3 restarts in 60s).';

        $this->client->expects($this->once())
            ->method('cancel')
            ->with('run-fail');

        $this->dispatchCancelEvent();

        $this->assertSame(RunActivityStateEnum::Cancelling, $this->state->activity);
    }

    #[Test]
    public function cancelFailedWithRuntimePollErrorWhenCancelThrowsShowsRecoveryBlock(): void
    {
        $this->state->activity = RunActivityStateEnum::Failed;
        $this->state->handle = new RunHandle('run-fail');
        $this->state->lastRuntimePollError = 'Controller process has crashed too many times (3 restarts in 60s).';

        $this->client->expects($this->once())
            ->method('cancel')
            ->willThrowException(new \RuntimeException('Controller stdin pipe is not available.'));

        $this->dispatchCancelEvent();

        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
        $this->assertCount(1, $this->state->transcript);
        $text = $this->state->transcript[0]->text;
        $this->assertStringContainsString('Cancel failed: Controller stdin pipe is not available.', $text);
        $this->assertStringContainsString('Please restart the agent', $text);
    }

    #[Test]
    public function cancelCancelledDoesNotCallClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Cancelled;
        $this->state->handle = new RunHandle('run-cxl');

        $this->client->expects($this->never())
            ->method('cancel');

        $this->dispatchCancelEvent();
    }

    // ── No handle — no cancel ───────────────────────────────────

    #[Test]
    public function cancelActiveButNoHandleDoesNotCallClient(): void
    {
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->handle = null;

        $this->client->expects($this->never())
            ->method('cancel');

        $this->dispatchCancelEvent();
    }

    // ── Cancel exception transitions to Failed ──────────────────

    #[Test]
    public function cancelExceptionTransitionsToFailed(): void
    {
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->handle = new RunHandle('run-err');

        $this->client->expects($this->once())
            ->method('cancel')
            ->willThrowException(new \RuntimeException('Connection lost'));

        // The CancelListener logs info() before cancel attempt, then
        // logs error() when cancel fails in capture mode.
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('info');
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Cancel command failed'),
                $this->callback(static fn (array $ctx) => 'run-err' === ($ctx['run_id'] ?? null)
                    && $ctx['exception'] instanceof \RuntimeException),
            );

        $this->dispatchCancelEvent();

        // Activity transitions to Failed (not Cancelling) on cancel failure.
        $this->assertSame(RunActivityStateEnum::Failed, $this->state->activity);
    }

    #[Test]
    public function cancelExceptionWithCaptureDisabledRethrows(): void
    {
        $this->state->activity = RunActivityStateEnum::Running;
        $this->state->handle = new RunHandle('run-crash');

        $this->client->expects($this->once())
            ->method('cancel')
            ->willThrowException(new \RuntimeException('Connection lost'));

        // The CancelListener calls logger->info() before attempting cancel.
        // With capture disabled, no error log is emitted — the exception
        // is rethrown directly from the catch block.
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('info');
        $this->logger->expects($this->never())
            ->method('error');
        $this->logger->expects($this->never())
            ->method('notice');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection lost');

        $this->dispatchCancelEvent(captureErrorEnv: '0');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Register the CancelListener and extract its CancelEvent handler,
     * then invoke it (without needing a real CancelEvent — the closure
     * doesn't use the $event parameter).
     */
    private function dispatchCancelEvent(?string $captureErrorEnv = '1'): ChatScreen
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, 'test-session', $promptEditor);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($this->client)
            ->withState($this->state)
            ->withScreen($screen)
            ->build();

        $eventDispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $eventDispatcher->addSubscriber(new RuntimeExceptionPolicySubscriber(
            new RuntimeErrorCaptureConfig(captureErrors: '0' !== $captureErrorEnv),
            new NullLogger(),
        ));
        $boundary = new RuntimeExceptionBoundary($eventDispatcher);

        $listener = new CancelListener(
            $this->logger,
            $boundary,
        );
        $listener->register($context);

        // Extract and invoke the CancelEvent handler
        $dispatcher = $tui->getEventDispatcher();
        $listeners = $dispatcher->getListeners(CancelEvent::class);
        $this->assertNotEmpty($listeners, 'CancelEvent listener was not registered');
        ($listeners[0])(new CancelEvent(new TextWidget()));

        return $screen;
    }
}
