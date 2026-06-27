<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\ResumeCanonicalEventsFixture;
use Ineersa\Tui\Tests\Support\ResumeSessionInitializerTestFactory;
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
    public function testSessionInitializerReconstructsCanonicalBlocksOnVirtualScreen(): void
    {
        $initializer = ResumeSessionInitializerTestFactory::create($this->createStub(EntityManagerInterface::class), $this->projectDir);
        $state = new TuiSessionState(self::SESSION_ID, true);
        $blocks = $initializer->buildInitialTranscript($state);

        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks($blocks);

        $screen = $harness->plainScreenText();

        $this->assertStringContainsString('Let me think about this request carefully.', $screen);
        $this->assertStringContainsString('Here is the answer you requested.', $screen);
        $this->assertStringContainsString('read', $screen);
        $this->assertStringContainsString('/tmp/example.txt', $screen);
        $this->assertStringContainsString('FILE CONTENTS HERE', $screen);
        $this->assertStringContainsString('turn cancelled', $screen);
        $this->assertStringNotContainsString('● Running…', $screen);
        $this->assertStringNotContainsString('[2J', $screen);
        $this->assertStringNotContainsString('[3J', $screen);
    }

    #[Test]
    public function testResumePickerOverlayShowsAndHidesCleanlyOnVirtualScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'resume-picker-virtual');
        $overlay = new PickerOverlay();
        $header = new TextWidget(text: 'Resume session — arrows move, Enter resumes, Esc cancels', truncate: true);
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
