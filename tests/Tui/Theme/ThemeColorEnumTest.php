<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemeColorEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeColorEnum::class)]
final class ThemeColorEnumTest extends TestCase
{
    public function testForReasoningMapsMaxToThinkingMax(): void
    {
        $this->assertSame(ThemeColorEnum::ThinkingMax, ThemeColorEnum::forReasoning('max'));
    }

    public function testThinkingMaxCaseExists(): void
    {
        $this->assertSame('thinking_max', ThemeColorEnum::ThinkingMax->value);
    }
}
