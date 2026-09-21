<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Exception\RenderException;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Behavior coverage against upstream Renderer after removing Hatfield's
 * CachedWidthValidationRenderer.
 */
final class UpstreamRendererBehaviorTest extends TestCase
{
    #[Test]
    public function itRejectsOverWideOutput(): void
    {
        $root = new ContainerWidget();
        $root->add(new FixedLineWidget('this-line-is-too-wide-for-ten-columns'));

        $this->expectException(RenderException::class);
        (new Renderer())->renderFrame($root, columns: 10, rows: 5);
    }

    #[Test]
    public function itAcceptsFittingOutputAndRevalidatesAfterWidthShrink(): void
    {
        $root = new ContainerWidget();
        $root->add(new FixedLineWidget('exactly-ten')); // 11 chars
        $renderer = new Renderer();

        $lines = $renderer->renderFrame($root, columns: 20, rows: 5);
        $this->assertGreaterThanOrEqual(1, \count($lines));

        $this->expectException(RenderException::class);
        $renderer->renderFrame($root, columns: 10, rows: 5);
    }
}

/**
 * Leaf widget that returns a raw line without wrapping, so Renderer width
 * validation remains observable.
 */
final class FixedLineWidget extends AbstractWidget
{
    public function __construct(private readonly string $line)
    {
    }

    public function render(RenderContext $context): array
    {
        return [$this->line];
    }
}
