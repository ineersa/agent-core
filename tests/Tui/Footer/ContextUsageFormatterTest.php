<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Footer;

use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Tests\Support\ContextUsageTestAppConfig;
use Ineersa\Tui\Theme\ThemeColorEnum;
use PHPUnit\Framework\TestCase;

final class ContextUsageFormatterTest extends TestCase
{
    public function testFormatsFooterStyleDetail(): void
    {
        $formatter = new ContextUsageFormatter(ContextUsageTestAppConfig::withContextWindow(272_000));
        $formatted = $formatter->format('deepseek/deepseek-v4-flash', 97_900);

        $this->assertNotNull($formatted);
        $this->assertSame('36% 97.9k/272.0k', $formatted['text']);
        $this->assertSame(ThemeColorEnum::Success, $formatted['color']);
    }

    public function testUsesWarningAndErrorThresholds(): void
    {
        $formatter = new ContextUsageFormatter(ContextUsageTestAppConfig::withContextWindow(100_000));

        $warning = $formatter->format('deepseek/deepseek-v4-flash', 60_000);
        $this->assertNotNull($warning);
        $this->assertSame(ThemeColorEnum::Warning, $warning['color']);

        $error = $formatter->format('deepseek/deepseek-v4-flash', 80_000);
        $this->assertNotNull($error);
        $this->assertSame(ThemeColorEnum::Error, $error['color']);
    }
}
