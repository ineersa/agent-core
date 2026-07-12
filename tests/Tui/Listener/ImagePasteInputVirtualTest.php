<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use Ineersa\Tui\Listener\ImagePasteInputListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Tests\ImagePaste\DelayedFakeClipboardImageReader;
use Ineersa\Tui\Tests\ImagePaste\FakeClipboardImageReader;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\TickEvent;

#[CoversClass(ImagePasteInputListener::class)]
final class ImagePasteInputVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('image-paste-virtual');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function ctrlVInsertsPlaceholderImmediatelyBeforeAsyncReadCompletes(): void
    {
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $temp = $this->tempDir.'/paste-virtual.png';
        file_put_contents($temp, $png);

        $harness = new VirtualTuiHarness(sessionId: 'paste-virtual');
        $state = new TuiSessionState('paste-virtual');
        $ticks = new TuiTickDispatcher();
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withTicks($ticks)
            ->build();

        $reader = new DelayedFakeClipboardImageReader(ClipboardImageReadResultDTO::image($temp), delayPolls: 3);
        $listener = new ImagePasteInputListener(
            $reader,
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        );
        $listener->register($context);
        $harness->startInputLoop();

        $harness->sendInput("\x16");
        $this->assertStringContainsString('[Image #1]', $harness->screen()->promptEditor()->getText());
        $this->assertSame(1, $state->pastedImagePasteInProgressIndex);
        $this->assertArrayNotHasKey(1, $state->pastedImagePendingByIndex);

        $this->dispatchTicks($ticks, 5);
        $this->assertArrayHasKey(1, $state->pastedImagePendingByIndex);
        $this->assertNull($state->pastedImagePasteInProgressIndex);
    }

    #[Test]
    public function secondCtrlVWhileReadingIsRejected(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'paste-dup');
        $state = new TuiSessionState('paste-dup');
        $ticks = new TuiTickDispatcher();
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withTicks($ticks)
            ->build();

        $reader = new DelayedFakeClipboardImageReader(
            ClipboardImageReadResultDTO::noImage('no image'),
            delayPolls: 10,
        );
        $listener = new ImagePasteInputListener(
            $reader,
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        );
        $listener->register($context);
        $harness->startInputLoop();

        $harness->sendInput("\x16");
        $harness->sendInput("\x16");

        $plain = $harness->plainScreenText();
        $this->assertStringContainsString('Already reading', $plain);
    }

    #[Test]
    public function bracketedTextPasteStillInsertsPlainText(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'paste-bracket');
        $state = new TuiSessionState('paste-bracket');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $listener = new ImagePasteInputListener(
            new FakeClipboardImageReader(ClipboardImageReadResultDTO::noImage('no image')),
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        );
        $listener->register($context);
        $harness->startInputLoop();

        $harness->sendInput("\x1b[200~bracket_paste_ok\x1b[201~");

        $this->assertStringContainsString('bracket_paste_ok', $harness->screen()->promptEditor()->getText());
        $this->assertStringNotContainsString('[Image #', $harness->screen()->promptEditor()->getText());
    }

    #[Test]
    public function removingPlaceholderBeforePollCompletesDiscardsStagedBytes(): void
    {
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $temp = $this->tempDir.'/paste-discard.png';
        file_put_contents($temp, $png);

        $harness = new VirtualTuiHarness(sessionId: 'paste-discard');
        $state = new TuiSessionState('paste-discard');
        $ticks = new TuiTickDispatcher();
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withTicks($ticks)
            ->build();

        $reader = new DelayedFakeClipboardImageReader(ClipboardImageReadResultDTO::image($temp), delayPolls: 5);
        $listener = new ImagePasteInputListener(
            $reader,
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        );
        $listener->register($context);
        $harness->startInputLoop();

        $harness->sendInput("\x16");
        $harness->screen()->promptEditor()->setText('');
        $this->dispatchTicks($ticks, 8);

        $this->assertArrayNotHasKey(1, $state->pastedImagePendingByIndex);
        $this->assertNull($state->pastedImagePasteInProgressIndex);
    }

    #[Test]
    public function inFlightSubmitIsBlockedWhilePlaceholderRemains(): void
    {
        $state = new TuiSessionState('paste-submit-block');
        $state->pastedImagePasteInProgressIndex = 1;
        $text = '[Image #1] describe';

        $harness = new VirtualTuiHarness(sessionId: 'paste-submit-block');
        $screen = $harness->screen();
        $blockFactory = new TranscriptBlockFactory();

        $method = new \ReflectionMethod(\Ineersa\Tui\Listener\SubmitListener::class, 'promotePastedImagesInPrompt');
        $result = $method->invoke(
            null,
            $text,
            $state,
            $screen,
            $blockFactory,
            new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                new \Ineersa\CodingAgent\Config\AppConfig(
                    tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                    logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                    sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                    cwd: $this->tempDir,
                ),
                $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            ),
            new \Ineersa\Tui\ImagePaste\PastedImageSubmissionService(
                new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
                new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                    new \Ineersa\CodingAgent\Config\AppConfig(
                        tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                        logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                        sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                        cwd: $this->tempDir,
                    ),
                    $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                ),
                new \Ineersa\CodingAgent\Config\AppConfig(
                    tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                    logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                    sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                    cwd: $this->tempDir,
                ),
                new TranscriptBlockFactory(),
                new TestLogger(),
            ),
            new TestLogger(),
            new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
        );

        $this->assertNull($result);
        $plain = $harness->plainScreenText();
        $this->assertStringContainsString('still being read', $plain);
    }

    private function dispatchTicks(TuiTickDispatcher $ticks, int $count): void
    {
        $ref = new \ReflectionClass($ticks);
        $prop = $ref->getProperty('handlers');
        $handlers = $prop->getValue($ticks);
        $event = new TickEvent();
        for ($i = 0; $i < $count; ++$i) {
            foreach ($handlers as $handler) {
                $handler($event);
            }
        }
    }
}
