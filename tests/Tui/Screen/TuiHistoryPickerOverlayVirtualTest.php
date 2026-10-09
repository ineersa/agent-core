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

    #[Test]
    public function testIndexedPagesNavigateBothDirectionsAndSelectSparsePromptsThroughNativeInput(): void
    {
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('history-picker-pages');
        try {
            $sessionId = 'paged-history';
            $sessionStore = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                new \Ineersa\CodingAgent\Config\AppConfig(tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'), logging: new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $directory),
                $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                new \Symfony\Component\EventDispatcher\EventDispatcher(),
            );
            $events = [];
            for ($i = 70; $i >= 1; --$i) {
                $events[] = \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($sessionId, $i, 'agent_command_applied', ['kind' => 'follow_up', 'text' => 'Prompt number '.$i]);
                $events[] = \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($sessionId, $i, 'turn_advanced', ['turn_no' => $i]);
            }
            $store = \Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory::create($sessionStore, $events);
            $provider = new \Ineersa\CodingAgent\Session\SessionHistoryProvider($store, new \Ineersa\CodingAgent\Session\RunHistoryIndex(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()), new \Psr\Log\NullLogger()));
            $harness = new VirtualTuiHarness(sessionId: $sessionId);
            $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
            $switcher->expects($this->once())->method('selectHistoryTurn')->with(33);
            $picker = new HistoryPickerController($harness->tui(), $harness->screen(), new TuiSessionState($sessionId), $provider, $switcher);
            (new HistoryCommandHandler($picker))->handle(new SlashCommand('history', '', '/history'));
            $this->assertStringContainsString('Prompt number 1', $harness->plainScreenText());
            for ($i = 0; $i < 32; ++$i) {
                $harness->tui()->handleInput("\x1b[A");
            }
            $this->assertStringContainsString('Older prompts...', $harness->plainScreenText());
            $harness->tui()->handleInput("\n");
            $this->assertStringContainsString('Prompt number 33', $harness->plainScreenText());
            $this->assertStringContainsString('Newer prompts...', $harness->plainScreenText());
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
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function sampleHistory(): HistoryView
    {
        return new HistoryView(
            prompts: [
                new HistoryPromptView(1, 'hello', 1),
                new HistoryPromptView(2, 'Can you create file', 2),
            ],
            selectedAnchor: 2,
        );
    }
}
