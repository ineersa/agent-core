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
        $this->assertStringContainsString('[Skills]', $joined);
        $this->assertStringContainsString('alpha', $joined);
        $this->assertStringContainsString('beta', $joined);
        $this->assertStringContainsString('won /win/SKILL.md', $joined);
        $this->assertStringContainsString('ignored /lose/SKILL.md', $joined);
        $this->assertStringContainsString('ctrl+r to expand', $joined);
    }

    #[Test]
    public function testConflictRendersMessageWithWinnerAndLoserPaths(): void
    {
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
                key: 'prompts',
                label: 'Prompts',
                items: [],
                conflicts: [
                    new LoadedResourceConflictDTO(
                        name: 'review',
                        winnerPath: '/global/review.md',
                        loserPath: '/project/review.md',
                        message: 'name collision',
                    ),
                ],
            ),
        ]);

        $widget = new LoadedResourcesWidget();
        $widget->setSummary($summary);
        $lines = $widget->render($this->context());
        $joined = implode("\n", $lines);

        $this->assertStringContainsString('review: name collision (won /global/review.md, ignored /project/review.md)', $joined);
    }

    #[Test]
    public function testConflictWithEmptyWinnerAndMessageRendersMessageOnly(): void
    {
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
                key: 'extensions',
                label: 'Extensions',
                items: [],
                conflicts: [
                    new LoadedResourceConflictDTO(
                        name: 'BadExt',
                        winnerPath: '',
                        loserPath: 'Ineersa\\Bad\\Extension',
                        message: 'Failed to load extension',
                    ),
                ],
            ),
        ]);

        $widget = new LoadedResourcesWidget();
        $widget->setSummary($summary);
        $lines = $widget->render($this->context());
        $joined = implode("\n", $lines);

        $this->assertStringContainsString('BadExt: Failed to load extension', $joined);
        $this->assertStringNotContainsString('won (unknown)', $joined);
        $this->assertStringNotContainsString('ignored', $joined);
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
        $this->assertStringContainsString('fix-bug — /prompts/fix-bug.md', $joined);
        $this->assertStringContainsString('ctrl+r to collapse', $joined);
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
