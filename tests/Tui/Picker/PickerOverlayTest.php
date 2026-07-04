<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PickerOverlay::class)]
final class PickerOverlayTest extends TestCase
{
    public function testMountSetsIsOpen(): void
    {
        $overlay = new PickerOverlay();
        self::assertFalse($overlay->isOpen());
        self::assertNull($overlay->listWidget());

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

        self::assertTrue($overlay->isOpen());
        self::assertSame($listWidget, $overlay->listWidget());
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
        self::assertTrue($overlay->isOpen());

        $overlay->close();
        self::assertFalse($overlay->isOpen());
        self::assertNull($overlay->listWidget());
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

        self::assertFalse($overlay->isOpen());
    }

    public function testListWidgetReturnsNullBeforeMount(): void
    {
        $overlay = new PickerOverlay();
        self::assertNull($overlay->listWidget());
    }

    public function testScreenReturnsNullBeforeMount(): void
    {
        $overlay = new PickerOverlay();
        self::assertNull($overlay->screen());
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

        self::assertSame($screen, $overlay->screen());
    }

    public function testIsOpenFalseByDefault(): void
    {
        $overlay = new PickerOverlay();
        self::assertFalse($overlay->isOpen());
    }

    public function testCloseBeforeMountIsNoOp(): void
    {
        $overlay = new PickerOverlay();
        $overlay->close(); // should not throw
        self::assertFalse($overlay->isOpen());
        self::assertNull($overlay->listWidget());
        self::assertNull($overlay->screen());
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

        $container = $this->pickerContainerFromOverlay($overlay);
        $overlayIdx = $this->rootChildIndex($tui, $container);

        self::assertGreaterThan($editorIdx, $overlayIdx, 'Default picker overlay must render below the editor');
        self::assertLessThan($footerIdx, $overlayIdx, 'Default picker overlay must render above the footer');
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

        self::fail('Widget not found in TUI root children');
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
