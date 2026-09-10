<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriterAliasInstaller;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Widget\SelectListKeybindings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PickerOverlay::class)]
final class PickerOverlayTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function transcriptHeights(): iterable
    {
        yield 'fits viewport' => [2];
        yield 'overheight' => [60];
    }

    #[DataProvider('transcriptHeights')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPickerTransitionsDoNotRepaintUnchangedTranscript(int $lineCount): void
    {
        SynchronizedCursorScreenWriterAliasInstaller::install();
        $output = new VirtualTerminal(columns: 100, rows: 24);
        // Exercise the writer's physical-viewport decisions without a live process.
        $dispatcher = new EventDispatcher();
        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getEventDispatcher')->willReturn($dispatcher);
        $terminal->method('getColumns')->willReturn(100);
        $terminal->method('getRows')->willReturn(24);
        $terminal->method('isVirtual')->willReturn(false);
        $terminal->method('write')->willReturnCallback($output->write(...));
        $terminal->method('showCursor')->willReturnCallback($output->showCursor(...));
        $terminal->method('hideCursor')->willReturnCallback($output->hideCursor(...));
        $tui = new Tui(terminal: $terminal, eventDispatcher: $dispatcher);
        $screen = new ChatScreen(new DefaultTheme(new ThemePalette('test', [])), 'picker-paint', new PromptEditor());
        $screen->mount($tui);
        $screen->setTranscriptBlocks([(new TranscriptBlockFactory())->system(
            runId: 'picker-paint',
            text: implode("\n", array_map(static fn (int $i): string => 'Transcript sentinel '.$i, range(1, $lineCount))),
            seq: 1,
        )]);
        $screen->promptEditor()->replaceText('Draft sentinel');
        $tui->setFocus($screen->editorWidget());
        $tui->requestRender();
        $tui->processRender();
        $buffer = new ScreenBuffer(width: 100, height: 24);
        $buffer->write($output->consumeOutput());

        foreach ([8, 1, 5] as $itemCount) {
            $overlay = new PickerOverlay();
            $list = new SelectListWidget(
                items: array_map(
                    static fn (int $i): array => ['value' => (string) $i, 'label' => 'Choice sentinel '.$i],
                    range(1, $itemCount),
                ),
                keybindings: SelectListKeybindings::standard(),
            );
            $list->onCancel(static fn () => $overlay->close());
            $overlay->mount($tui, $screen, $list, new TextWidget(text: 'Picker header sentinel'));
            $tui->processRender();
            $delta = $output->consumeOutput();
            $buffer->write($delta);
            $this->assertStringNotContainsString('Transcript sentinel', $delta, 'Opening must retain the unchanged transcript.');
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $this->assertStringContainsString('Picker header sentinel', $buffer->getScreen());
            $this->assertStringContainsString('Choice sentinel 1', $buffer->getScreen());

            $tui->handleInput("\x1b[B");
            $tui->processRender();
            $delta = $output->consumeOutput();
            $buffer->write($delta);
            $this->assertStringNotContainsString('Transcript sentinel', $delta);
            $this->assertStringContainsString('→ Choice sentinel '.min(2, $itemCount), $buffer->getScreen());

            $tui->handleInput("\x1b");
            $tui->processRender();
            $delta = $output->consumeOutput();
            $buffer->write($delta);
            $this->assertStringNotContainsString('Transcript sentinel', $delta, 'Closing must retain the unchanged transcript.');
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $this->assertStringNotContainsString('Picker header sentinel', $buffer->getScreen());
            $this->assertStringNotContainsString('Choice sentinel', $buffer->getScreen());
            $this->assertStringContainsString('Draft sentinel', $buffer->getScreen());
            $this->assertStringContainsString('Transcript sentinel '.$lineCount, $buffer->getScreen());
        }
    }

    public function testMountSetsIsOpen(): void
    {
        $overlay = new PickerOverlay();
        $this->assertFalse($overlay->isOpen());
        $this->assertNull($overlay->listWidget());

        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test', [])),
            'test-session',
            $promptEditor,
        );

        $tui = new Tui();
        $screen->mount($tui);

        $listWidget = new SelectListWidget(items: [
            ['value' => 'a', 'label' => 'A'],
            ['value' => 'b', 'label' => 'B'],
        ]);
        $header = new TextWidget(text: 'Test header', truncate: true);

        $overlay->mount($tui, $screen, $listWidget, $header);

        $this->assertTrue($overlay->isOpen());
        $this->assertSame($listWidget, $overlay->listWidget());
    }

    public function testCloseResetsState(): void
    {
        $overlay = new PickerOverlay();

        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test', [])),
            'test-session',
            $promptEditor,
        );

        $tui = new Tui();
        $screen->mount($tui);

        $listWidget = new SelectListWidget(items: [['value' => 'a', 'label' => 'A']]);
        $header = new TextWidget(text: 'H', truncate: true);

        $overlay->mount($tui, $screen, $listWidget, $header);
        $this->assertTrue($overlay->isOpen());

        $overlay->close();
        $this->assertFalse($overlay->isOpen());
        $this->assertNull($overlay->listWidget());
    }

    public function testCloseIsIdempotent(): void
    {
        $overlay = new PickerOverlay();

        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test', [])),
            'test-session',
            $promptEditor,
        );

        $tui = new Tui();
        $screen->mount($tui);

        $listWidget = new SelectListWidget(items: [['value' => 'a', 'label' => 'A']]);
        $header = new TextWidget(text: 'H', truncate: true);

        $overlay->mount($tui, $screen, $listWidget, $header);
        $overlay->close();
        $overlay->close(); // second call — should be no-op

        $this->assertFalse($overlay->isOpen());
    }

    public function testListWidgetReturnsNullBeforeMount(): void
    {
        $overlay = new PickerOverlay();
        $this->assertNull($overlay->listWidget());
    }

    public function testScreenReturnsNullBeforeMount(): void
    {
        $overlay = new PickerOverlay();
        $screenRef = new \ReflectionProperty($overlay, 'screen');
        $this->assertNull($screenRef->getValue($overlay));
    }

    public function testScreenReturnsChatScreenAfterMount(): void
    {
        $overlay = new PickerOverlay();

        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test', [])),
            'test-session',
            $promptEditor,
        );

        $tui = new Tui();
        $screen->mount($tui);

        $listWidget = new SelectListWidget(items: [['value' => 'a', 'label' => 'A']]);
        $header = new TextWidget(text: 'H', truncate: true);

        $overlay->mount($tui, $screen, $listWidget, $header);

        $screenRef = new \ReflectionProperty($overlay, 'screen');
        $this->assertSame($screen, $screenRef->getValue($overlay));
    }

    public function testIsOpenFalseByDefault(): void
    {
        $overlay = new PickerOverlay();
        $this->assertFalse($overlay->isOpen());
    }

    public function testCloseBeforeMountIsNoOp(): void
    {
        $overlay = new PickerOverlay();
        $overlay->close(); // should not throw
        $this->assertFalse($overlay->isOpen());
        $this->assertNull($overlay->listWidget());
        $screenRef = new \ReflectionProperty($overlay, 'screen');
        $this->assertNull($screenRef->getValue($overlay));
    }

    public function testDefaultMountInsertsOverlayAfterEditor(): void
    {
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test', [])),
            'test-session',
            $promptEditor,
        );

        $tui = new Tui();
        $screen->mount($tui);

        $editorIdx = $this->rootChildIndex($tui, $screen->promptEditor()->getWidget());
        $footerIdx = $this->rootChildIndex($tui, $this->footerWidget($screen));

        $listWidget = new SelectListWidget(items: [
            ['value' => 'a', 'label' => 'A'],
        ]);
        $header = new TextWidget(text: 'Test', truncate: true);

        $overlay = new PickerOverlay();
        $overlay->mount($tui, $screen, $listWidget, $header);

        $this->assertTrue($overlay->isOpen());
        $this->assertSame($listWidget, $overlay->listWidget());

        $container = $this->pickerContainerFromOverlay($overlay);
        $overlayIdx = $this->rootChildIndex($tui, $container);

        $this->assertGreaterThan($editorIdx, $overlayIdx, 'Default picker overlay must render below the editor');
        $this->assertLessThan($footerIdx, $overlayIdx, 'Default picker overlay must render above the footer');
    }

    /**
     * @return list<\Symfony\Component\Tui\Widget\AbstractWidget>
     */
    private function rootChildren(Tui $tui): array
    {
        $rootProp = new \ReflectionProperty(Tui::class, 'root');
        /** @var ContainerWidget $root */
        $root = $rootProp->getValue($tui);

        return array_values($root->all());
    }

    private function rootChildIndex(Tui $tui, object $widget): int
    {
        $children = $this->rootChildren($tui);
        foreach ($children as $i => $child) {
            if ($child === $widget) {
                return $i;
            }
        }

        $this->fail('Widget not found in TUI root children');
    }

    private function pickerContainerFromOverlay(PickerOverlay $overlay): ContainerWidget
    {
        $prop = new \ReflectionProperty(PickerOverlay::class, 'container');
        /** @var ContainerWidget $container */
        $container = $prop->getValue($overlay);

        return $container;
    }

    private function footerWidget(ChatScreen $screen): object
    {
        $prop = new \ReflectionProperty(ChatScreen::class, 'footerWidget');

        return $prop->getValue($screen);
    }
}
