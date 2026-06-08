<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
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

        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getColumns')->willReturn(120);
        $terminal->method('getRows')->willReturn(40);
        $terminal->method('isKittyProtocolActive')->willReturn(false);
        $terminal->method('isVirtual')->willReturn(true);

        $this->tui = new Tui(terminal: $terminal);

        $theme = new readonly class implements TuiTheme {
            public function name(): string
            {
                return 'test';
            }

            public function color(ThemeColorEnum $color, string $text): string
            {
                return $text;
            }

            public function accent(string $text): string
            {
                return $text;
            }

            public function text(string $text): string
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
        };

        $this->screen = new ChatScreen(
            theme: $theme,
            sessionId: 'test-session',
            promptEditor: new PromptEditor(),
        );
    }

    // ── Pre-mount safety ──

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
        self::assertCount($initialCount + 1, $children, 'Root should have one more child after overlay insertion');

        // Overlay exists.
        $ids = array_map(static fn (AbstractWidget $w) => $w->getId(), $children);
        self::assertContains('approval-overlay', $ids, 'Overlay widget must be present in root container');

        // Overlay is not the last child — footer must be after it.
        // ChatScreen::mount() appends footer last; insertOverlayBeforeEditor()
        // re-adds footer as the last step, so it should always be last.
        $lastIdx = \count($children) - 1;
        $overlayIdx = array_search('approval-overlay', $ids, true);
        self::assertLessThan($lastIdx, $overlayIdx, 'Overlay must not appear after footer');
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
        self::assertContains('approval-overlay', $idsBefore, 'Overlay must be present before removal');

        // Remove overlay.
        $this->screen->removeOverlay($overlay);

        // Verify overlay is gone and count decreased.
        $childrenAfter = $this->getRootChildren();
        $idsAfter = array_map(static fn (AbstractWidget $w) => $w->getId(), $childrenAfter);

        self::assertNotContains('approval-overlay', $idsAfter, 'Overlay must be removed from the widget tree');
        self::assertCount($initialCount - 1, $childrenAfter, 'Root should have one fewer child after overlay removal');
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
        self::assertCount(1, $sessionSegment);
        self::assertStringContainsString('new-session-id', $sessionSegment[0]->text);
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
        self::assertCount(1, $sessionSegment);
        self::assertStringContainsString('new-session-id', $sessionSegment[0]->text);
    }
}
