<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Transcript\TranscriptEntry;
use Ineersa\Tui\Transcript\TranscriptWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptEntry::class)]
#[CoversClass(TranscriptWidget::class)]
final class TranscriptWidgetTest extends TestCase
{
    public function testEmptyTranscriptShowsWelcome(): void
    {
        $widget = new TranscriptWidget();
        $context = new TuiRenderContext();

        $lines = $widget->render($context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('Welcome to Agent Core', $lines[0]);
    }

    public function testWithEntries(): void
    {
        $widget = new TranscriptWidget();
        $widget->addEntry(new TranscriptEntry(text: 'Hello', role: 'user'));
        $widget->addEntry(new TranscriptEntry(text: 'Hi there', role: 'assistant'));

        $context = new TuiRenderContext();
        $lines = $widget->render($context);

        self::assertCount(2, $lines);
        self::assertStringContainsString('❯', $lines[0]); // user prefix
        self::assertStringContainsString('Hello', $lines[0]);
        self::assertStringContainsString('◇', $lines[1]); // assistant prefix
        self::assertStringContainsString('Hi there', $lines[1]);
    }

    public function testToolEntry(): void
    {
        $widget = new TranscriptWidget();
        $widget->addEntry(new TranscriptEntry(text: 'ls -la', role: 'tool'));

        $lines = $widget->render(new TuiRenderContext());

        self::assertStringContainsString('●', $lines[0]);
        self::assertStringContainsString('ls -la', $lines[0]);
    }

    public function testSetEntriesReplaces(): void
    {
        $widget = new TranscriptWidget();
        $widget->addEntry(new TranscriptEntry(text: 'old', role: 'system'));
        $widget->setEntries([
            new TranscriptEntry(text: 'new1', role: 'user'),
            new TranscriptEntry(text: 'new2', role: 'assistant'),
        ]);

        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(2, $lines);
        self::assertStringContainsString('new1', $lines[0]);
        self::assertStringContainsString('new2', $lines[1]);
    }
}
