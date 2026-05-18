<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\PromptEditorWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptEditorWidget::class)]
final class PromptEditorWidgetTest extends TestCase
{
    #[Test]
    public function rendersDefaultPlaceholder(): void
    {
        $widget = new PromptEditorWidget();
        $lines = $widget->render(new TuiRenderContext());

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('❯', $lines[0]);
        $this->assertStringContainsString('Type a message', $lines[0]);
    }

    #[Test]
    public function rendersCustomPlaceholder(): void
    {
        $widget = new PromptEditorWidget(placeholder: 'Ask something...');
        $lines = $widget->render(new TuiRenderContext());

        $this->assertStringContainsString('Ask something', $lines[0]);
    }

    #[Test]
    public function rendersWithPromptText(): void
    {
        $widget = new PromptEditorWidget();
        $widget->setPromptText('/help');

        $lines = $widget->render(new TuiRenderContext());

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('/help', $lines[0]);
    }
}
