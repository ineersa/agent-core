<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PickerOverlay::class)]
final class PickerOverlayTest extends TestCase
{
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
        $this->assertNull($overlay->screen());
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

        $this->assertSame($screen, $overlay->screen());
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
        $this->assertNull($overlay->screen());
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

        self::assertGreaterThan($editorIdx, $overlayIdx, 'Default picker overlay must render below the editor');
        self::assertLessThan($footerIdx, $overlayIdx, 'Default picker overlay must render above the footer');
    }


    /**
     * @return list<\Symfony\Component\Tui\Widget\AbstractWidget>
     */
    private function rootChildren(Tui $tui): array
    {
        $rootProp = new \ReflectionProperty(Tui::class, 'root');
        /** @var \Symfony\Component\Tui\Widget\ContainerWidget $root */
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

    private function pickerContainerFromOverlay(PickerOverlay $overlay): \Symfony\Component\Tui\Widget\ContainerWidget
    {
        $prop = new \ReflectionProperty(PickerOverlay::class, 'container');
        /** @var \Symfony\Component\Tui\Widget\ContainerWidget $container */
        $container = $prop->getValue($overlay);

        return $container;
    }

    private function footerWidget(ChatScreen $screen): object
    {
        $prop = new \ReflectionProperty(ChatScreen::class, 'footerWidget');

        return $prop->getValue($screen);
    }
}
