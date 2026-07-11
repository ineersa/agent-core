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
use Ineersa\Tui\Tests\ImagePaste\FakeClipboardImageReader;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function ctrlVInsertsImagePlaceholderOnRealInputPath(): void
    {
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $temp = $this->tempDir.'/paste-virtual.png';
        file_put_contents($temp, $png);

        $harness = new VirtualTuiHarness(sessionId: 'paste-virtual');
        $state = new TuiSessionState('paste-virtual');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $listener = new ImagePasteInputListener(
            new FakeClipboardImageReader(ClipboardImageReadResultDTO::image($temp)),
            new PastedImageValidationService(new ImageToolConfig(), new TestLogger()),
            new TranscriptBlockFactory(),
            new TestLogger(),
        );
        $listener->register($context);
        $harness->startInputLoop();

        $harness->sendInput("\x16");
        usleep(50_000);

        $this->assertStringContainsString('[Image #1]', $harness->screen()->promptEditor()->getText());
        $this->assertArrayHasKey(1, $state->pastedImagePendingByIndex);
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
        usleep(50_000);

        $this->assertStringContainsString('bracket_paste_ok', $harness->screen()->promptEditor()->getText());
        $this->assertStringNotContainsString('[Image #', $harness->screen()->promptEditor()->getText());
    }
}
