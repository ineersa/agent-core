<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic startup layout proof without tmux.
 *
 * Test thesis: stable user-visible startup elements (logo, welcome, idle
 * status, session id in footer) render from the mounted ChatScreen tree
 * without wall-clock pane polling.
 */
final class TuiStartupVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-startup-session';

    #[Test]
    public function testStartupLayoutRendersStableElements(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $factory = new TranscriptBlockFactory();
        $welcome = $factory->system(
            runId: self::SESSION_ID,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        );

        $harness->screen()->setTranscriptBlocks([$welcome]);
        $harness->screen()->setWorkingVisible(true);
        $harness->screen()->setWorkingMessage(null);

        $screen = $harness->plainScreenText();

        self::assertStringContainsString('█', $screen, 'Hatfield logo (box drawing) missing');
        self::assertStringContainsString('Welcome to Hatfield', $screen, 'Welcome message missing');
        self::assertStringContainsString('● idle', $screen, 'Idle working status missing');
        self::assertStringContainsString('session '.self::SESSION_ID, $screen, 'Session id in footer missing');
    }
}
