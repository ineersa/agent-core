<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Navigation thesis: /history must show user prompts only and position/edit semantics
 * are wired through the real slash-command path.
 */
final class TuiHistoryPickerOverlayVirtualTest extends TestCase
{
    #[Test]
    public function testHistoryPickerRendersUserPromptsOnly(): void
    {
        $sessionId = 'history-overlay-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleHistory());
        $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));

        (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));

        $screen = $harness->plainScreenText();
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to edit prompt (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
        $this->assertSame(1, substr_count($screen, 'Can you create file'));
        $this->assertSame(0, substr_count($screen, 'Done! Created file'), 'assistant turns are not picker rows');
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
        $this->assertSame(1, substr_count($screen, 'Session history — Enter to edit prompt (Esc to close)'));
        $this->assertSame(1, substr_count($screen, 'hello'));
    }

    private function sampleHistory(): HistoryView
    {
        return new HistoryView(
            prompts: [
                new HistoryPromptView(1, 'hello'),
                new HistoryPromptView(2, 'Can you create file'),
            ],
            positionTurnNo: 2,
        );
    }
}
