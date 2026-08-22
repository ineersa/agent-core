<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\SelectListWidget;

final class ThemeStyleSheetFactoryTest extends TestCase
{
    #[Test]
    public function testCreateQuestionChoiceListScopesRulesToQuestionClassOnly(): void
    {
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                ThemeColorEnum::Accent->value => 'cyan',
                ThemeColorEnum::Text->value => 'white',
                ThemeColorEnum::Muted->value => 'gray',
            ],
        );

        $sheet = (new ThemeStyleSheetFactory())->createQuestionChoiceList($palette);
        $this->assertInstanceOf(StyleSheet::class, $sheet);

        $rules = (new \ReflectionProperty($sheet, 'rules'))->getValue($sheet);
        $this->assertIsArray($rules);
        $this->assertArrayHasKey('.question-choice-list::selected', $rules);
        $this->assertArrayHasKey('.question-choice-list::selected:focus', $rules);
        $this->assertArrayHasKey('.question-choice-list::label', $rules);
        $this->assertArrayHasKey('.question-choice-list::description', $rules);
        $this->assertArrayHasKey('.question-choice-list::scroll-info', $rules);
        $this->assertArrayNotHasKey(SelectListWidget::class.'::selected', $rules);
        $this->assertTrue($rules['.question-choice-list::selected']->getBold());
    }
}
