<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class TuiResumeSessionVirtualTest extends TestCase
{
    private const string SESSION_ID = 'resume-virtual-session';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('tui-resume-virtual');
        ResumeCanonicalEventsFixture::write($this->projectDir, self::SESSION_ID);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    #[Test]
    public function testResumeShowsRestoringNoticeInsteadOfReconstructingTheArchive(): void
    {
        $store = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(new \Ineersa\CodingAgent\Config\AppConfig(new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'), new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $this->projectDir),
            $this->createStub(EntityManagerInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher());
        $initializer = new \Ineersa\Tui\Application\SessionInitializer($store, new \Ineersa\Tui\Transcript\TranscriptBlockFactory());
        $state = new TuiSessionState(self::SESSION_ID, true);
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks($initializer->buildInitialTranscript($state));
        $this->assertStringContainsString('Restoring session...', $harness->plainScreenText());
        $this->assertFalse($state->sessionReady);
        $this->assertSame(0, $state->lastSeq);
    }

    #[Test]
    public function testResumePickerOverlayShowsAndHidesCleanlyOnVirtualScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'resume-picker-virtual');
        $overlay = new PickerOverlay();
        $header = new TextWidget(text: 'Resume session — arrows move, Enter resumes, d deletes, Esc cancels', truncate: true);
        $listWidget = new SelectListWidget(items: [
            ['value' => '42', 'label' => '#42 — Example session'],
        ]);

        $overlay->mount($harness->tui(), $harness->screen(), $listWidget, $header);
        $openScreen = $harness->plainScreenText();
        $this->assertStringContainsString('Resume session', $openScreen);
        $this->assertStringContainsString('#42 — Example session', $openScreen);

        $overlay->close();
        $closedScreen = $harness->plainScreenText();
        $this->assertStringNotContainsString('arrows move, Enter resumes', $closedScreen);
    }
}
