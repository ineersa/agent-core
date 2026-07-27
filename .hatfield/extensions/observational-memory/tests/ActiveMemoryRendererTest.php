<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\ActiveMemoryRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: server render uses exact header/sections/order; empty input yields empty string.
 */
final class ActiveMemoryRendererTest extends TestCase
{
    public function testRendersHeaderSectionsAndDeterministicOrder(): void
    {
        $text = ActiveMemoryRenderer::render(
            [
                ['reflection_id' => 'r2', 'content' => 'Second', 'position' => 1],
                ['reflection_id' => 'r1', 'content' => 'First', 'position' => 0],
            ],
            [
                [
                    'observation_id' => 'o2',
                    'content' => 'Later observation',
                    'relevance' => 'high',
                    'timestamp' => '2026-07-26 13:00',
                ],
                [
                    'observation_id' => 'o1',
                    'content' => 'Earlier observation',
                    'relevance' => 'medium',
                    'timestamp' => '2026-07-26 12:00',
                ],
            ],
        );

        $this->assertStringStartsWith(ActiveMemoryRenderer::HEADER, $text);
        $this->assertStringContainsString("## Reflections\n[r1] First\n[r2] Second", $text);
        $this->assertStringContainsString(
            "## Observations\n[o1] 2026-07-26 12:00 [medium] Earlier observation\n[o2] 2026-07-26 13:00 [high] Later observation",
            $text,
        );
        $this->assertStringNotContainsString('source_refs', $text);
    }

    public function testEmptyInputsRenderEmpty(): void
    {
        $this->assertSame('', ActiveMemoryRenderer::render([], []));
    }
}
