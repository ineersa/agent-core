<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(HistoryCommandHandler::class)]
final class HistoryCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHandleReturnsNoOp(): void
    {
        $picker = $this->openablePicker();
        $handler = new HistoryCommandHandler($picker);

        $command = new SlashCommand(name: 'history', args: '', originalText: '/history');
        $result = $handler->handle($command);

        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testHandleOpensPickerWithHistoryData(): void
    {
        $picker = $this->openablePicker();
        $handler = new HistoryCommandHandler($picker);

        $command = new SlashCommand(name: 'history', args: '', originalText: '/history');
        $handler->handle($command);

        $this->assertTrue(self::pickerOpen($picker), 'Handler should cause picker to open');
    }

    private function openablePicker(): HistoryPickerController
    {
        $history = new HistoryView(
            prompts: [
                new HistoryPromptView(
                    turnNo: 1,
                    promptText: 'Root turn',
                ),
            ],
            positionTurnNo: 1,
        );

        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);

        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $tui = new Tui();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            new PromptEditor(),
        );
        $screen->mount($tui);
        $state = new TuiSessionState(sessionId: 'test-session', resuming: false);
        $picker = new HistoryPickerController($tui, $screen, $state, $provider, $switcher);

        return $picker;
    }

    private static function pickerOpen(HistoryPickerController $controller): bool
    {
        $overlayRef = new \ReflectionProperty($controller, 'overlay');
        $overlay = $overlayRef->getValue($controller);

        return null !== $overlay && $overlay->isOpen();
    }
}
