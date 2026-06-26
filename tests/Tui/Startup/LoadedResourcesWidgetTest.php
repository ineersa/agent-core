<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Startup;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Startup\LoadedResourcesWidget;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoadedResourcesWidgetTest extends TestCase
{
    #[Test]
    public function testRendersCollapsedSectionsAndWarningConflicts(): void
    {
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
                key: 'skills',
                label: 'Skills',
                items: [
                    new LoadedResourceItemDTO('alpha', '/a/SKILL.md'),
                    new LoadedResourceItemDTO('beta', '/b/SKILL.md'),
                ],
                conflicts: [
                    new LoadedResourceConflictDTO('gamma', '/win/SKILL.md', '/lose/SKILL.md'),
                ],
            ),
        ]);

        $widget = new LoadedResourcesWidget();
        $widget->setSummary($summary);
        $lines = $widget->render($this->context());

        $joined = implode("\n", $lines);
        self::assertStringContainsString('[Skills]', strip_tags($joined));
        self::assertStringContainsString('alpha', $joined);
        self::assertStringContainsString('beta', $joined);
        self::assertStringContainsString('won /win/SKILL.md', $joined);
        self::assertStringContainsString('ignored /lose/SKILL.md', $joined);
        self::assertStringContainsString('ctrl+r to expand', $joined);
    }

    #[Test]
    public function testExpandedModeShowsSourcePaths(): void
    {
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
                key: 'prompts',
                label: 'Prompts',
                items: [new LoadedResourceItemDTO('fix-bug', '/prompts/fix-bug.md')],
            ),
        ]);

        $widget = new LoadedResourcesWidget();
        $widget->setSummary($summary);
        $widget->setExpanded(true);
        $lines = $widget->render($this->context());

        $joined = implode("\n", $lines);
        self::assertStringContainsString('fix-bug — /prompts/fix-bug.md', $joined);
        self::assertStringContainsString('ctrl+r to collapse', $joined);
    }

    private function context(): TuiRenderContext
    {
        $palette = new ThemePalette('test', [
            ThemeColorEnum::MarkdownHeading->value => '33',
            ThemeColorEnum::Muted->value => '90',
            ThemeColorEnum::Dim->value => '2',
            ThemeColorEnum::Warning->value => '33;1',
        ]);

        return new TuiRenderContext(120, 40, new DefaultTheme($palette));
    }
}
