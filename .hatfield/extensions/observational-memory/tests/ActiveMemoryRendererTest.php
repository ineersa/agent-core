<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ActiveMemoryRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: server render uses exact header/sections/order with 12-char model-facing IDs;
 * empty input yields empty string. Full stored SHA-256 must not appear in compact text.
 */
final class ActiveMemoryRendererTest extends TestCase
{
    public function testRendersHeaderSectionsAndDeterministicOrder(): void
    {
        $r1 = 'aaaaaaaaaaa1'.str_repeat('b', 52);
        $r2 = 'aaaaaaaaaaa2'.str_repeat('c', 52);
        $o1 = 'bbbbbbbbbbb1'.str_repeat('d', 52);
        $o2 = 'bbbbbbbbbbb2'.str_repeat('e', 52);

        $text = ActiveMemoryRenderer::render(
            [
                ['reflection_id' => $r2, 'content' => 'Second', 'position' => 1],
                ['reflection_id' => $r1, 'content' => 'First', 'position' => 0],
            ],
            [
                [
                    'observation_id' => $o2,
                    'content' => 'Later observation',
                    'relevance' => 'high',
                    'timestamp' => '2026-07-26 13:00',
                ],
                [
                    'observation_id' => $o1,
                    'content' => 'Earlier observation',
                    'relevance' => 'medium',
                    'timestamp' => '2026-07-26 12:00',
                ],
            ],
        );

        $this->assertStringStartsWith(ActiveMemoryRenderer::HEADER, $text);
        $this->assertStringContainsString("## Reflections\n[aaaaaaaaaaa1] First\n[aaaaaaaaaaa2] Second", $text);
        $this->assertStringContainsString(
            "## Observations\n[bbbbbbbbbbb1] 2026-07-26 12:00 [medium] Earlier observation\n[bbbbbbbbbbb2] 2026-07-26 13:00 [high] Later observation",
            $text,
        );
        $this->assertStringNotContainsString('source_refs', $text);
        $this->assertStringNotContainsString($r1, $text);
        $this->assertStringNotContainsString($r2, $text);
        $this->assertStringNotContainsString($o1, $text);
        $this->assertStringNotContainsString($o2, $text);
        $this->assertMatchesRegularExpression('/\[aaaaaaaaaaa1\]/', $text);
        $this->assertMatchesRegularExpression('/\[bbbbbbbbbbb1\]/', $text);
    }

    public function testEmptyInputsRenderEmpty(): void
    {
        $this->assertSame('', ActiveMemoryRenderer::render([], []));
    }
}
