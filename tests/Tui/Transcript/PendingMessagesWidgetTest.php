<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\PendingMessagesWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class PendingMessagesWidgetTest extends TestCase
{
    #[Test]
    public function pastedTabsAreNormalizedBeforeWrapping(): void
    {
        $widget = new PendingMessagesWidget(new DefaultTheme(new ThemePalette('pending-tabs', [])));
        $widget->setMessages([
            "PHPUnit tests\tpass\t1m32s\thttps://github.com/symfony/symfony/actions/runs/19999999999",
        ]);

        $rows = $widget->render(new RenderContext(40, 10));

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString("\t", $row);
            $this->assertLessThanOrEqual(40, AnsiUtils::visibleWidth($row));
        }
        $this->assertStringContainsString('PHPUnit tests   pass   1m32s', implode("\n", $rows));
    }
}
