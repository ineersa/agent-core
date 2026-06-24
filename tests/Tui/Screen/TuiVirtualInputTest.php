<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic keyboard input proof without tmux.
 *
 * Test thesis: virtual terminal input routes through Symfony TUI focus + EditorWidget
 * into PromptEditor text state.
 */
final class TuiVirtualInputTest extends TestCase
{
    #[Test]
    public function testVirtualInputRoutesTypedTextToPromptEditor(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'virtual-input-session');

        try {
            $harness->startInputLoop();
            $harness->sendInput('hello virtual');

            self::assertSame('hello virtual', $harness->screen()->editorText());

            $screen = $harness->plainScreenText();
            self::assertStringContainsString('hello virtual', $screen, 'Typed text should appear on rendered screen');
        } finally {
            $harness->stopInputLoop();
        }
    }
}
