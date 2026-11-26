<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryEntryView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Session\SessionHistoryProvider;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Tui\Tui;

#[CoversClass(HistoryPickerController::class)]
final class HistoryPickerControllerTest extends TestCase
{
    private Tui $tui;
    private ChatScreen $screen;
    private TuiSessionState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tui = new Tui();
        $promptEditor = new PromptEditor();
        $this->screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            $promptEditor,
        );
        $this->screen->mount($this->tui);
        $this->state = new TuiSessionState(
            sessionId: 'test-session',
            resuming: false,
        );
    }

    #[Test]
    public function testIsOpenIsFalseInitially(): void
    {
        $provider = $this->createStub(HistoryProviderInterface::class);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new HistoryPickerController($this->tui, $this->screen, $this->state, $provider, $switcher);

        $this->assertFalse(self::pickerOpen($controller));
    }

    /**
     * Thesis: /history renders HistoryView turns directly; SessionHistoryProvider is the
     * single user-prompt filter, so the controller must not re-filter roles.
     */
    #[Test]
    public function testBuildItemsRendersProviderUserPrompts(): void
    {
        $history = $this->createUserPromptHistory();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = HistoryPickerController::buildItems($history, $theme);

        $this->assertCount(2, $items);
        $this->assertSame(['1', '3'], array_column($items, 'value'));
        $this->assertStringContainsString('Hello', $items[0]['label']);
        $this->assertStringContainsString('Follow-up', $items[1]['label']);
        foreach ($items as $item) {
            $this->assertStringNotContainsString('├─', $item['label']);
            $this->assertStringNotContainsString('└─', $item['label']);
        }
    }

    #[Test]
    public function testInitialSelectedIndexPrefersNextUserPromptAfterTip(): void
    {
        // Tip at turn 1 (before second user prompt turn 3).
        $history = $this->createUserPromptHistory(positionTurnNo: 1);
        $this->assertSame(1, HistoryPickerController::initialSelectedIndex($history));
    }

    #[Test]
    public function testBuildItemsEmptyHistory(): void
    {
        $history = new HistoryView(entries: [], selectedAnchor: 0);
        $theme = new DefaultTheme(new ThemePalette('test'));
        $this->assertSame([], HistoryPickerController::buildItems($history, $theme));
    }

    #[Test]
    public function testOpenMountsOverlayWithHistory(): void
    {
        $history = $this->createUserPromptHistory();
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new HistoryPickerController($this->tui, $this->screen, $this->state, $provider, $switcher);
        $controller->open();

        $this->assertTrue(self::pickerOpen($controller));
        $controller->closePicker();
        $this->assertFalse(self::pickerOpen($controller));
    }

    #[Test]
    public function testOpenShowsStatusWhenEmpty(): void
    {
        $history = new HistoryView(entries: [], selectedAnchor: 0);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new HistoryPickerController($this->tui, $this->screen, $this->state, $provider, $switcher);
        $controller->open();

        $this->assertFalse(self::pickerOpen($controller));
    }

    #[Test]
    public function testOnSelectCallsSelectHistoryTurnForSelectedPrompt(): void
    {
        $history = $this->createUserPromptHistory();
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);

        $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switcher->expects($this->once())->method('selectHistoryTurn')->with(3);

        $controller = new HistoryPickerController($this->tui, $this->screen, $this->state, $provider, $switcher);
        $controller->open();

        $overlayRef = new \ReflectionProperty($controller, 'overlay');
        $overlay = $overlayRef->getValue($controller);
        $this->assertNotNull($overlay);
        $list = $overlay->listWidget();
        $this->assertNotNull($list);
        $list->setSelectedIndex(1);
        // Enter confirms selection (select_confirm keybinding).
        $list->handleInput("\n");
    }

    /** Provider contract: HistoryView turns are already user prompts only. */
    #[Test]
    public function testHistoryPickerRendersUserPromptsAndFinalAnswers(): void
    {
        $sessionId = 'history-overlay-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleHistory());
        $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));

        (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));

        $screen = $harness->plainScreenText();
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to select position (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
        $this->assertSame(1, substr_count($screen, 'Can you create file'));
        $this->assertSame(1, substr_count($screen, 'Done! Created file'));
        $this->assertStringContainsString('assistant: Done! Created file', $screen);
    }

    #[Test]
    public function testHistoryPickerRemountDoesNotDuplicateRows(): void
    {
        $sessionId = 'history-remount-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleHistory());
        $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));

        (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));
        $picker->closePicker();
        $picker->open();
        $screen = $harness->plainScreenText();
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to select position (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
    }

    #[Test]
    public function testIndexedPagesNavigateBothDirectionsAndSelectSparsePromptsThroughNativeInput(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('history-picker-pages');
        try {
            $sessionId = 'paged-history';
            $sessionStore = new HatfieldSessionStore(
                new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $directory),
                $this->createStub(EntityManagerInterface::class),
                new EventDispatcher(), lockFactory: new LockFactory(new FlockStore()));
            $events = [];
            for ($i = 70; $i >= 1; --$i) {
                $events[] = RunEvent::forAppend($sessionId, $i, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 'Prompt number '.$i]);
                $events[] = RunEvent::forAppend($sessionId, $i, 'turn_advanced', ['turn_no' => $i]);
            }
            $store = HistoryEventStoreFactory::create($sessionStore, $events);
            $provider = new SessionHistoryProvider($store, new RunHistoryIndex(new LockFactory(new FlockStore()), new NullLogger()));
            $harness = new VirtualTuiHarness(sessionId: $sessionId);
            $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
            $switcher->expects($this->once())->method('selectHistoryTurn')->with(33);
            $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $switcher);
            (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));
            $this->assertStringContainsString('Prompt number 1', $harness->plainScreenText());
            for ($i = 0; $i < 32; ++$i) {
                $harness->tui()->handleInput("\x1b[A");
            }
            $this->assertStringContainsString('Older entries...', $harness->plainScreenText());
            $harness->tui()->handleInput("\n");
            $this->assertStringContainsString('Prompt number 33', $harness->plainScreenText());
            $this->assertStringContainsString('Newer entries...', $harness->plainScreenText());
            $harness->tui()->handleInput("\x1b[B");
            $harness->tui()->handleInput("\n");
            $this->assertStringContainsString('Prompt number 1', $harness->plainScreenText());
            for ($i = 0; $i < 32; ++$i) {
                $harness->tui()->handleInput("\x1b[A");
            }
            $harness->tui()->handleInput("\n");
            $harness->tui()->handleInput("\n");
            $this->assertStringNotContainsString('Session history', $harness->plainScreenText());
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function sampleHistory(): HistoryView
    {
        return new HistoryView(
            entries: [
                new HistoryEntryView(1, 'hello', 1),
                new HistoryEntryView(2, 'Can you create file', 2),
                new HistoryEntryView(2, 'Done! Created file', 2, 'assistant'),
            ],
            selectedAnchor: 2,
        );
    }

    private function createUserPromptHistory(int $positionTurnNo = 3): HistoryView
    {
        return new HistoryView(
            entries: [
                new HistoryEntryView(turnNo: 1, text: 'Hello', anchor: 1),
                new HistoryEntryView(turnNo: 3, text: 'Follow-up', anchor: 3),
            ],
            selectedAnchor: $positionTurnNo,
        );
    }

    private static function pickerOpen(HistoryPickerController $controller): bool
    {
        $overlayRef = new \ReflectionProperty($controller, 'overlay');
        $overlay = $overlayRef->getValue($controller);

        return null !== $overlay && $overlay->isOpen();
    }
}
