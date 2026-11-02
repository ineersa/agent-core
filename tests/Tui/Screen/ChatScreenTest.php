<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Question\QuestionOverlayWidget;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Tests for ChatScreen overlay management.
 *
 * Uses a real Symfony TUI with a mocked terminal so we can mount
 * widgets and inspect the widget tree without a real terminal.
 */
class ChatScreenTest extends TestCase
{
    private Tui $tui;
    private ChatScreen $screen;

    protected function setUp(): void
    {
        parent::setUp();

        $dispatcher = new EventDispatcher();
        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getEventDispatcher')->willReturn($dispatcher);
        $terminal->method('getColumns')->willReturn(120);
        $terminal->method('getRows')->willReturn(40);
        $terminal->method('isKittyProtocolActive')->willReturn(false);
        $terminal->method('isVirtual')->willReturn(true);

        $this->tui = new Tui(terminal: $terminal, eventDispatcher: $dispatcher);

        $theme = new readonly class implements TuiTheme {
            public function color(ThemeColorEnum $color, string $text): string
            {
                return $text;
            }

            public function muted(string $text): string
            {
                return $text;
            }

            public function success(string $text): string
            {
                return $text;
            }

            public function warning(string $text): string
            {
                return $text;
            }

            public function error(string $text): string
            {
                return $text;
            }

            public function getPalette(): \Ineersa\Tui\Theme\ThemePalette
            {
                return new \Ineersa\Tui\Theme\ThemePalette(
                    name: 'test',
                    colors: [],
                );
            }
        };

        $this->screen = new ChatScreen(
            theme: $theme,
            sessionId: 'test-session',
            promptEditor: new PromptEditor());
    }

    public function testBackgroundRowsStayBelowEditorAcrossOverlayAndAttentionRemoval(): void
    {
        $harness = new VirtualTuiHarness(columns: 90, rows: 30);
        $screen = $harness->screen();
        $screen->promptEditor()->setText('EDITOR_ANCHOR');
        $rows = [
            ['kind' => 'agent', 'label' => 'scout [⚠ needs input, backgrounded] 2% 4.5k/272k model', 'status' => ''],
            ['kind' => 'fork', 'label' => 'fork [⚠ needs input, backgrounded]', 'status' => ''],
        ];
        $screen->setBackgroundRows($rows);
        $overlay = new TextWidget(text: 'QUESTION_ANCHOR');
        $screen->insertOverlayBeforeEditor($overlay);
        $text = $harness->plainScreenText();
        $this->assertLessThan(strpos($text, 'EDITOR_ANCHOR'), strpos($text, 'QUESTION_ANCHOR'));
        $this->assertStringNotContainsString('scout [', $text);
        $screen->removeOverlay($overlay);
        $screen->setBackgroundRows([$rows[0]]);
        $text = $harness->plainScreenText();
        $this->assertLessThan(strpos($text, 'scout ['), strpos($text, 'EDITOR_ANCHOR'));
        $this->assertStringNotContainsString('fork [', $text);
        $screen->setBackgroundRows([]);
        $this->assertStringNotContainsString('needs input', $harness->plainScreenText());
    }

    public function testRealQuestionKeepsPriorityOverBackgroundRowsOnShortTerminal(): void
    {
        $harness = new VirtualTuiHarness(columns: 80, rows: 10);
        $screen = $harness->screen();
        $screen->setBackgroundRows(array_fill(0, 4, ['kind' => 'agent', 'label' => 'BACKGROUND_ANCHOR', 'status' => 'running']));
        $question = new QuestionOverlayWidget();
        $question->add(new TextWidget('Human input required'));
        $question->add(new MarkdownWidget('QUESTION_ANCHOR'));
        $question->add(new SelectListWidget(items: [['value' => 'yes', 'label' => 'ANSWER_ANCHOR']]));
        $screen->insertOverlayBeforeEditor($question);
        $screen->compactHeaderWidget()->setSnapshot(new CompactHeaderSnapshot(skills: ['testing']));
        foreach (['EDITOR_ANCHOR', "EDITOR_ANCHOR\nsecond line"] as $draft) {
            $screen->promptEditor()->setText($draft);
            $text = $harness->plainScreenText();
            $this->assertStringContainsString('QUESTION_ANCHOR', $text);
            $this->assertStringContainsString('ANSWER_ANCHOR', $text);
            $this->assertStringContainsString('EDITOR_ANCHOR', $text);
            $this->assertStringNotContainsString('BACKGROUND_ANCHOR', $text);
        }
        $screen->removeOverlay($question);
        $this->assertStringContainsString('BACKGROUND_ANCHOR', $harness->plainScreenText());
    }

    public function testBackgroundMetadataInvalidatesOnlyChangedRowsAndIdenticalTextIsNoOp(): void
    {
        $harness = new VirtualTuiHarness();
        $screen = $harness->screen();
        $screen->promptEditor()->setText('UNCHANGED_EDITOR');
        $rows = [['kind' => 'fork', 'label' => 'fork 7% 19.3k/272k model', 'status' => '']];
        $screen->setBackgroundRows($rows);
        $harness->render();
        $harness->terminal()->clearOutput();
        $rows[0]['label'] = 'fork 9% 23.4k/272k model';
        $harness->tui()->onTick(static fn () => $screen->setBackgroundRows($rows));
        $harness->tui()->tick();
        $harness->tui()->processRender();
        $output = $harness->terminal()->consumeOutput();
        $this->assertStringContainsString('23.4k', $output);
        $this->assertStringNotContainsString('UNCHANGED_EDITOR', $output);
        $this->assertStringNotContainsString("\033[2J", $output);
        $this->assertStringNotContainsString("\033[H", $output);
        $screen->setBackgroundRows($rows);
        $harness->tui()->tick();
        $this->assertSame('', $harness->terminal()->consumeOutput());
    }

    public function testAfterEditorOverlayStaysBeforeBackgroundRowsAndReservesItsHeight(): void
    {
        $harness = new VirtualTuiHarness(columns: 48, rows: 30, sessionId: 'overlay');
        $screen = $harness->screen();
        $screen->promptEditor()->setText('EDITOR_ANCHOR');
        $rows = [];
        foreach (range(1, 4) as $index) {
            $rows[] = ['kind' => 'agent', 'label' => 'ACTIVE_'.$index, 'status' => 'running'];
        }
        $screen->setBackgroundRows($rows);
        $overlay = new TextWidget(text: "COMPLETION_ONE\nCOMPLETION_TWO");
        $screen->insertOverlayAfterEditor($overlay);
        foreach ([30, 10] as $height) {
            $harness->terminal()->simulateResize(48, $height);
            $text = $harness->plainScreenText();
            foreach (['EDITOR_ANCHOR', 'COMPLETION_ONE', 'COMPLETION_TWO', 'ACTIVE_1', 'session overlay'] as $anchor) {
                $this->assertStringContainsString($anchor, $text);
            }
            $this->assertLessThan(strpos($text, 'COMPLETION_ONE'), strpos($text, 'EDITOR_ANCHOR'));
            $this->assertLessThan(strpos($text, 'ACTIVE_1'), strpos($text, 'COMPLETION_TWO'));
            $this->assertLessThan(strpos($text, 'session overlay'), strpos($text, 'ACTIVE_1'));
        }
        $overlay->setText("COMPLETION_ONE\nCOMPLETION_TWO\nCOMPLETION_THREE");
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text);
        $this->assertStringContainsString('COMPLETION_THREE', $text);
        $this->assertStringContainsString('+4 more', $text);
        $screen->removeOverlay($overlay);
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('COMPLETION_', $text);
        $this->assertStringContainsString('ACTIVE_4', $text);
        $this->assertStringNotContainsString('more', $text);
    }

    public function testMixedBackgroundRowsAtNarrowTerminalKeepEditorUsable(): void
    {
        $harness = new VirtualTuiHarness(columns: 48, rows: 20);
        $screen = $harness->screen();
        $screen->promptEditor()->setText('EDITOR_ANCHOR');
        $rows = [];
        foreach (['agent', 'fork', 'bash', 'code_mode'] as $kind) {
            $rows[] = ['kind' => $kind, 'label' => $kind.' '.str_repeat('long label ', 20), 'status' => 'running'];
        }
        $screen->setBackgroundRows($rows);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text, $text);
        foreach (['agent', 'fork', 'bash', 'code_mode'] as $kind) {
            $this->assertStringContainsString($kind.' [running]', $text, $text);
        }
        $harness->terminal()->simulateResize(48, 12);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('EDITOR_ANCHOR', $text, $text);
    }

    // ── Pre-mount safety ──

    #[Test]
    public function testPersistentWriteReplacesTransientLifetimeWithIdenticalText(): void
    {
        $harness = new VirtualTuiHarness();
        $screen = $harness->screen();
        $screen->setTransientStatus('notice', 'Same visible text');
        $screen->setStatus('notice', 'Same visible text');
        $screen->clearTransientStatuses();
        $this->assertStringContainsString('Same visible text', $harness->plainScreenText());

        $screen->setTransientStatus('notice', 'Same visible text');
        $screen->clearTransientStatuses();
        $this->assertStringNotContainsString('Same visible text', $harness->plainScreenText());
    }

    #[Test]
    public function testInsertOverlayBeforeEditorThrowsBeforeMount(): void
    {
        $overlay = new TextWidget(text: 'test-overlay');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('insertOverlayBeforeEditor() requires ChatScreen to be mounted first');

        $this->screen->insertOverlayBeforeEditor($overlay);
    }

    #[Test]
    public function testRemoveOverlaySafeBeforeMount(): void
    {
        $overlay = new TextWidget(text: 'test-overlay');

        // Must not throw when called before mount.
        $this->screen->removeOverlay($overlay);

        // No assertion needed — if it throws, the test fails.
        $this->addToAssertionCount(1);
    }

    // ── Insertion order ──

    #[Test]
    public function testInsertOverlayBeforeEditorPlacesOverlayNotAfterFooter(): void
    {
        // 1. Mount ChatScreen to populate the widget tree.
        $this->screen->mount($this->tui);

        $initialCount = \count($this->getRootChildren());

        // 2. Create a uniquely-named overlay and insert it.
        $overlay = new TextWidget(text: 'approval-overlay');
        $overlay->setId('approval-overlay');

        $this->screen->insertOverlayBeforeEditor($overlay);

        // 3. Inspect the root container's children.
        $children = $this->getRootChildren();

        // Widget count increased by exactly 1.
        $this->assertCount($initialCount + 1, $children, 'Root should have one more child after overlay insertion');

        // Overlay exists.
        $ids = array_map(static fn (AbstractWidget $w) => $w->getId(), $children);
        $this->assertContains('approval-overlay', $ids, 'Overlay widget must be present in root container');

        // Overlay is not the last child — footer must be after it.
        // ChatScreen::mount() appends footer last; insertOverlayBeforeEditor()
        // re-adds footer as the last step, so it should always be last.
        $lastIdx = \count($children) - 1;
        $overlayIdx = array_search('approval-overlay', $ids, true);
        $this->assertLessThan($lastIdx, $overlayIdx, 'Overlay must not appear after footer');
    }

    // ── Removal ──

    #[Test]
    public function testRemoveOverlayRemovesWidget(): void
    {
        $this->screen->mount($this->tui);

        $overlay = new TextWidget(text: 'approval-overlay');
        $overlay->setId('approval-overlay');

        $this->screen->insertOverlayBeforeEditor($overlay);

        $initialCount = \count($this->getRootChildren());

        // Verify overlay is present.
        $idsBefore = array_map(static fn (AbstractWidget $w) => $w->getId(), $this->getRootChildren());
        $this->assertContains('approval-overlay', $idsBefore, 'Overlay must be present before removal');

        // Remove overlay.
        $this->screen->removeOverlay($overlay);

        // Verify overlay is gone and count decreased.
        $childrenAfter = $this->getRootChildren();
        $idsAfter = array_map(static fn (AbstractWidget $w) => $w->getId(), $childrenAfter);

        $this->assertNotContains('approval-overlay', $idsAfter, 'Overlay must be removed from the widget tree');
        $this->assertCount($initialCount - 1, $childrenAfter, 'Root should have one fewer child after overlay removal');
    }

    // ── Session ID update ──

    #[Test]
    public function testUpdateSessionIdUpdatesFooterSegmentText(): void
    {
        // Call updateSessionId to change the session displayed in the footer.
        $this->screen->updateSessionId('new-session-id');

        // Assert the default footer segment text now reflects the new session ID.
        $segments = $this->getFooterSegments();
        $sessionSegment = array_values(array_filter(
            $segments,
            static fn (FooterSegment $s) => str_contains($s->text, 'session'),
        ));
        $this->assertCount(1, $sessionSegment);
        $this->assertStringContainsString('new-session-id', $sessionSegment[0]->text);
    }

    #[Test]
    public function testUpdateSessionIdAfterMountUpdatesFooter(): void
    {
        $this->screen->mount($this->tui);

        $this->screen->updateSessionId('new-session-id');

        $segments = $this->getFooterSegments();
        $sessionSegment = array_values(array_filter(
            $segments,
            static fn (FooterSegment $s) => str_contains($s->text, 'session'),
        ));
        $this->assertCount(1, $sessionSegment);
        $this->assertStringContainsString('new-session-id', $sessionSegment[0]->text);
    }

    /**
     * Draft sessions (empty session ID) produce no default footer
     * segment, leaving only extension-contributed footer content.
     */
    #[Test]
    public function testDraftFooterHasNoSessionSegment(): void
    {
        $draftScreen = new ChatScreen(
            theme: $this->getTestTheme(),
            sessionId: '',
            promptEditor: new PromptEditor());

        $draftScreen->mount($this->tui);

        // Reflect into the draft screen's footer data provider.
        $screenRef = new \ReflectionClass($draftScreen);
        $fdProp = $screenRef->getProperty('footerDataProvider');
        /** @var FooterDataProvider $fd */
        $fd = $fdProp->getValue($draftScreen);

        $segments = $fd->getSegments();
        $this->assertEmpty(
            $segments,
            'Draft session with empty ID must produce zero default footer segments.',
        );
    }

    // ── Helpers ──

    /**
     * Reflect into the Tui's private $root container and return its children.
     *
     * @return list<AbstractWidget>
     */
    private function getRootChildren(): array
    {
        $tuiRef = new \ReflectionClass($this->tui);
        $rootProp = $tuiRef->getProperty('root');
        /** @var ContainerWidget $root */
        $root = $rootProp->getValue($this->tui);

        return array_values($root->all());
    }

    /**
     * Reflect into ChatScreen to read footer segments.
     *
     * @return list<FooterSegment>
     */
    private function getFooterSegments(): array
    {
        $screenRef = new \ReflectionClass($this->screen);
        $fdProp = $screenRef->getProperty('footerDataProvider');
        /** @var FooterDataProvider $fd */
        $fd = $fdProp->getValue($this->screen);

        return $fd->getSegments();
    }

    /**
     * Return a real ChatScreen-compatible theme for constructing
     * additional screens without leaking the setUp instance.
     */
    private function getTestTheme(): TuiTheme
    {
        return new readonly class implements TuiTheme {
            public function color(ThemeColorEnum $color, string $text): string
            {
                return $text;
            }

            public function muted(string $text): string
            {
                return $text;
            }

            public function success(string $text): string
            {
                return $text;
            }

            public function warning(string $text): string
            {
                return $text;
            }

            public function error(string $text): string
            {
                return $text;
            }

            public function getPalette(): \Ineersa\Tui\Theme\ThemePalette
            {
                return new \Ineersa\Tui\Theme\ThemePalette(name: 'test', colors: []);
            }
        };
    }
}
