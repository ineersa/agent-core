<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\AppShortcutKeybindings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: app shortcut action ids resolve both legacy control bytes and
 * Kitty CSI-u forms through Symfony Keybindings/KeyParser.
 */
#[CoversClass(AppShortcutKeybindings::class)]
final class AppShortcutKeybindingsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideShortcutSequences(): iterable
    {
        yield 'preview expansion legacy' => ['toggle_preview_expansion', "\x0f"];
        yield 'preview expansion kitty' => ['toggle_preview_expansion', "\x1b[111;5u"];
        yield 'loaded resources legacy' => ['toggle_loaded_resources', "\x12"];
        yield 'loaded resources kitty' => ['toggle_loaded_resources', "\x1b[114;5u"];
        yield 'subagent live legacy' => ['toggle_subagent_live', "\x1c"];
        yield 'subagent live kitty' => ['toggle_subagent_live', "\x1b[92;5u"];
        yield 'favorite model legacy' => ['cycle_favorite_model', "\x10"];
        yield 'favorite model kitty' => ['cycle_favorite_model', "\x1b[112;5u"];
        yield 'reasoning legacy' => ['cycle_reasoning', "\x1b[Z"];
        yield 'reasoning kitty' => ['cycle_reasoning', "\x1b[9;2u"];
        yield 'completion tab legacy' => ['trigger_completion', "\t"];
        yield 'completion tab kitty' => ['trigger_completion', "\x1b[9u"];
        yield 'image paste legacy' => ['paste_image', "\x16"];
        yield 'image paste kitty' => ['paste_image', "\x1b[118;5u"];
        yield 'newline ctrl+j legacy' => ['new_line', "\n"]; // ctrl+j is LF
        yield 'newline ctrl+j kitty' => ['new_line', "\x1b[106;5u"];
        yield 'newline shift+enter kitty' => ['new_line', "\x1b[13;2u"];
    }

    #[Test]
    #[DataProvider('provideShortcutSequences')]
    public function matchesLegacyAndKittyForms(string $action, string $sequence): void
    {
        $keys = AppShortcutKeybindings::create();

        $this->assertTrue($keys->matches($sequence, $action));
    }
}
