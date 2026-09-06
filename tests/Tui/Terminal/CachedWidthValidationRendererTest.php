<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\CachedWidthValidationRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Exception\RenderException;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class CachedWidthValidationRendererTest extends TestCase
{
    private const string UPSTREAM_RENDERER_SHA256 = '7d3ec0abe2f4126eb7d4303bf06f9fed4bb288878f44076cee15321e6cc663f5';

    #[Test]
    public function copiedRendererTracksTheLockedSymfonyRevision(): void
    {
        $this->assertSame(
            self::UPSTREAM_RENDERER_SHA256,
            hash_file('sha256', \dirname(__DIR__, 3).'/vendor/symfony/tui/Render/Renderer.php'),
            'Symfony Renderer changed. Rebase the app-owned copy and update this reviewed hash.',
        );
    }

    #[Test]
    public function unchangedRowsReuseValidationWhileChangedRowsStillFail(): void
    {
        $renderer = new CachedWidthValidationRenderer();
        $widget = $this->fixedLinesWidget(['safe']);
        $root = new ContainerWidget();
        $root->add($widget);

        $renderer->render($root, columns: 10, rows: 5);

        $widget->invalidate();
        $renderer->render($root, columns: 10, rows: 5);

        $this->setFixedLines($widget, ['this line is definitely wider than ten']);
        $widget->invalidate();

        $this->expectException(RenderException::class);
        $renderer->render($root, columns: 10, rows: 5);
    }

    #[Test]
    public function widthChangeRevalidatesPreviouslyAcceptedRows(): void
    {
        $renderer = new CachedWidthValidationRenderer();
        $widget = $this->fixedLinesWidget(['fifteen chars!!']);
        $root = new ContainerWidget();
        $root->add($widget);

        $renderer->render($root, columns: 20, rows: 5);

        $widget->invalidate();
        $this->expectException(RenderException::class);
        $renderer->render($root, columns: 10, rows: 5);
    }

    /**
     * @param list<string> $lines
     */
    private function fixedLinesWidget(array $lines): AbstractWidget
    {
        return new class($lines) extends AbstractWidget {
            /**
             * @param list<string> $lines
             */
            public function __construct(private array $lines)
            {
            }

            /**
             * @param list<string> $lines
             */
            public function replaceLines(array $lines): void
            {
                $this->lines = $lines;
            }

            public function render(RenderContext $context): array
            {
                return $this->lines;
            }
        };
    }

    /**
     * @param list<string> $lines
     */
    private function setFixedLines(AbstractWidget $widget, array $lines): void
    {
        if (!method_exists($widget, 'replaceLines')) {
            throw new \LogicException('Expected fixed-lines test widget.');
        }

        $widget->replaceLines($lines);
    }
}
