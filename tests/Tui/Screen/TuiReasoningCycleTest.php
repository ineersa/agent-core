<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Deterministic Shift+Tab reasoning status + editor border proof without tmux.
 *
 * Test thesis: virtual InputEvent dispatch for Shift+Tab updates
 * reasoning status text and editor frame ANSI colour without wall-clock polling.
 *
 * Uses a test-local listener mirroring ModelControlListener
 * UI side effects; model cycling is covered elsewhere.
 */
final class TuiReasoningCycleTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private const string SESSION_ID = 'virtual-reasoning-session';

    private const string SHIFT_TAB = "\x1b[Z";

    /** @var list<string> */
    private array $cycleQueue = [];

    private int $cycleIndex = 0;

    #[Test]
    public function testShiftTabUpdatesReasoningStatusAndEditorBorderColour(): void
    {
        $this->cycleQueue = ['minimal', 'low'];
        $this->cycleIndex = 0;

        $harness = new VirtualTuiHarness(
            columns: 120,
            rows: 60,
            sessionId: self::SESSION_ID,
            palette: VirtualTuiHarness::defaultVirtualPalette(),
        );
        $state = new TuiSessionState(self::SESSION_ID);
        $state->footerReasoning = 'off';

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $this->registerShiftTabReasoningListener($context);

        try {
            $harness->startInputLoop();

            $harness->screen()->applyEditorBorderColor('off');
            $harness->render();
            $offBorder = $this->editorBottomBorderSgr($harness->ansiOutput());
            $this->assertNotNull($offBorder, 'Editor border SGR should be readable before Shift+Tab');

            $harness->sendInput(self::SHIFT_TAB);
            $this->assertSame('minimal', $state->footerReasoning);

            $minimalScreen = $harness->plainScreenText();
            $this->assertStringContainsString('reasoning', $minimalScreen);
            $this->assertStringContainsString('minimal', $minimalScreen);

            $minimalBorder = $this->editorBottomBorderSgr($harness->ansiOutput());
            $this->assertNotNull($minimalBorder);
            $this->assertNotSame($offBorder, $minimalBorder, 'Border SGR should change off→minimal');

            $harness->sendInput(self::SHIFT_TAB);
            $this->assertSame('low', $state->footerReasoning);

            $lowScreen = $harness->plainScreenText();
            $this->assertStringContainsString('reasoning', $lowScreen);
            $this->assertStringContainsString('low', $lowScreen);

            $lowBorder = $this->editorBottomBorderSgr($harness->ansiOutput());
            $this->assertNotNull($lowBorder);
            $this->assertNotSame($minimalBorder, $lowBorder, 'Border SGR should change minimal→low');
        } finally {
            $harness->stopInputLoop();
        }
    }

    private function registerShiftTabReasoningListener(\Ineersa\Tui\Runtime\TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $screen = $context->screen;
        $tui = $context->tui;
        $test = $this;

        $tui->addListener(static function (InputEvent $event) use ($state, $screen, $test): void {
            if (self::SHIFT_TAB !== $event->getData()) {
                return;
            }
            $event->stopPropagation();

            $nextLevel = $test->dequeueNextReasoningLevel();
            if (null === $nextLevel) {
                return;
            }

            $state->footerReasoning = $nextLevel;
            $screen->registry()->setStatus('reasoning', $nextLevel);
            $screen->refresh();
            $screen->applyEditorBorderColor($nextLevel);
        }, priority: 95);
    }

    private function dequeueNextReasoningLevel(): ?string
    {
        if ($this->cycleIndex >= \count($this->cycleQueue)) {
            return null;
        }

        return $this->cycleQueue[$this->cycleIndex++];
    }

    /**
     * Extract the first SGR sequence on an editor frame line (horizontal rule).
     */
    private function editorBottomBorderSgr(string $ansi): ?string
    {
        $lines = explode("\n", $ansi);
        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = $lines[$i];
            if (!str_contains($line, '─')) {
                continue;
            }
            if (preg_match('/\\x1b\\[([0-9;]*)m/', $line, $m)) {
                $colourPart = $m[1];
                if ('' !== $colourPart) {
                    return $colourPart;
                }
            }
        }

        return null;
    }
}
