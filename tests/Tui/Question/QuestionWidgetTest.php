<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Question\QuestionWidget;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionWidget::class)]
final class QuestionWidgetTest extends TestCase
{
    private function createWidget(): QuestionWidget
    {
        return new QuestionWidget();
    }

    private function createContext(): TuiRenderContext
    {
        $palette = new ThemePalette(
            name: 'test',
            colors: [
                'warning' => 'yellow',
                'text' => '',
                'muted' => '#888',
                'accent' => 'cyan',
            ],
        );

        return new TuiRenderContext(theme: new DefaultTheme($palette));
    }

    private function makeRequest(
        QuestionKind $kind,
        string $prompt = 'Proceed?',
        ?string $header = null,
        array $choices = [],
        bool $secret = false,
    ): QuestionRequest {
        return new QuestionRequest(
            requestId: 'test-id',
            source: QuestionSource::Tui,
            kind: $kind,
            prompt: $prompt,
            header: $header,
            choices: $choices,
            secret: $secret,
        );
    }

    // ── Text questions ──

    public function testRenderTextQuestion(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(QuestionKind::Text, 'What filename?'));

        $lines = $widget->render($this->createContext());

        self::assertCount(3, $lines);
        self::assertStringContainsString('Human input required', $lines[0]);
        self::assertStringContainsString('What filename?', $lines[1]);
        self::assertStringContainsString('type answer and press Enter', $lines[2]);
    }

    public function testRenderTextQuestionSecret(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(QuestionKind::Text, 'Enter API key:', secret: true));

        $lines = $widget->render($this->createContext());

        self::assertCount(3, $lines);
        self::assertStringContainsString('answer will be hidden', $lines[2]);
    }

    // ── Confirm questions ──

    public function testRenderConfirmQuestion(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(QuestionKind::Confirm, 'Delete the file?'));

        $lines = $widget->render($this->createContext());

        self::assertCount(3, $lines);
        self::assertStringContainsString('Confirmation required', $lines[0]);
        self::assertStringContainsString('Delete the file?', $lines[1]);
        self::assertStringContainsString('y = yes, n = no', $lines[2]);
    }

    // ── Choice questions ──

    public function testRenderChoiceQuestion(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(
            QuestionKind::Choice,
            'Which strategy?',
            choices: [
                new QuestionOption('simple', 'Fast, minimal change'),
                new QuestionOption('robust', 'More complete implementation'),
            ],
        ));

        $lines = $widget->render($this->createContext());

        self::assertCount(4, $lines);
        self::assertStringContainsString('Choose an option', $lines[0]);
        self::assertStringContainsString('Which strategy?', $lines[1]);
        self::assertStringContainsString('1.', $lines[2]);
        self::assertStringContainsString('simple', $lines[2]);
        self::assertStringContainsString('Fast, minimal change', $lines[2]);
        self::assertStringContainsString('2.', $lines[3]);
        self::assertStringContainsString('robust', $lines[3]);
        self::assertStringContainsString('More complete implementation', $lines[3]);
    }

    public function testRenderChoiceOptionLabelOnly(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(
            QuestionKind::Choice,
            'Pick one:',
            choices: [new QuestionOption('alpha')],
        ));

        $lines = $widget->render($this->createContext());

        self::assertCount(3, $lines);
        self::assertStringContainsString('1.', $lines[2]);
        self::assertStringContainsString('alpha', $lines[2]);
        // No description, so no dash or description text
        self::assertStringNotContainsString('—', $lines[2]);
    }

    public function testRenderChoiceMultipleOptions(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(
            QuestionKind::Choice,
            'Select severity:',
            choices: [
                new QuestionOption('low', 'Minor issue'),
                new QuestionOption('medium', 'Notable concern'),
                new QuestionOption('high', 'Critical problem'),
            ],
        ));

        $lines = $widget->render($this->createContext());

        self::assertCount(5, $lines);
        self::assertStringContainsString('1.', $lines[2]);
        self::assertStringContainsString('low', $lines[2]);
        self::assertStringContainsString('2.', $lines[3]);
        self::assertStringContainsString('medium', $lines[3]);
        self::assertStringContainsString('3.', $lines[4]);
        self::assertStringContainsString('high', $lines[4]);
    }

    // ── Approval questions ──

    public function testRenderApprovalQuestion(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(QuestionKind::Approval, 'Run destructive command?'));

        $lines = $widget->render($this->createContext());

        self::assertCount(3, $lines);
        self::assertStringContainsString('Approval requested', $lines[0]);
        self::assertStringContainsString('Run destructive command?', $lines[1]);
        self::assertStringContainsString('y = approve, n = reject', $lines[2]);
    }

    // ── Custom header ──

    public function testRenderCustomHeader(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(
            QuestionKind::Confirm,
            'Overwrite?',
            header: 'Custom Confirmation',
        ));

        $lines = $widget->render($this->createContext());

        self::assertStringContainsString('Custom Confirmation', $lines[0]);
        self::assertStringNotContainsString('Confirmation required', $lines[0]);
    }

    // ── No request ──

    public function testRenderNoRequest(): void
    {
        $widget = $this->createWidget();

        $lines = $widget->render($this->createContext());

        self::assertSame([], $lines);
    }

    public function testRenderAfterClear(): void
    {
        $widget = $this->createWidget();
        $widget->setRequest($this->makeRequest(QuestionKind::Text, 'test'));
        $widget->setRequest(null);

        $lines = $widget->render($this->createContext());

        self::assertSame([], $lines);
    }
}
