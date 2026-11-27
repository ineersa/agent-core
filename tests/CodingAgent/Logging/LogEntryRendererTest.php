<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\LogEntry;
use Ineersa\CodingAgent\Logging\LogEntryRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class LogEntryRendererTest extends TestCase
{
    public function testJsonlEmitsOneCompactJsonObjectPerLine(): void
    {
        $output = new BufferedOutput();

        (new LogEntryRenderer())->render([
            new LogEntry(new \DateTimeImmutable('2026-05-18T10:00:00+00:00'), 'app', 'INFO', 'First', ['run_id' => 'r1', 'nested' => ['a' => 1]]),
            new LogEntry(new \DateTimeImmutable('2026-05-18T10:01:00+00:00'), 'app', 'ERROR', 'Second <tag>', [], [], '/logs/agent.log', 7),
        ], 'jsonl', $output);

        $lines = explode("\n", rtrim($output->fetch(), "\n"));

        $this->assertCount(2, $lines);
        $first = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        $second = json_decode($lines[1], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('First', $first['message']);
        $this->assertSame(['run_id' => 'r1', 'nested' => ['a' => 1]], $first['context']);
        $this->assertSame('Second <tag>', $second['message']);
        $this->assertSame('/logs/agent.log', $second['sourceFile']);
        $this->assertSame(7, $second['lineNumber']);
    }

    public function testTableRendersEntries(): void
    {
        $output = new BufferedOutput();

        (new LogEntryRenderer())->render([
            new LogEntry(new \DateTimeImmutable('2026-05-18T10:00:00+00:00'), 'app', 'WARNING', 'Disk almost full'),
        ], 'table', $output);

        $text = $output->fetch();
        $this->assertStringContainsString('2026-05-18 10:00:00', $text);
        $this->assertStringContainsString('WARNING', $text);
        $this->assertStringContainsString('Disk almost full', $text);
    }

    public function testEmptyEntriesReportNoMatches(): void
    {
        $output = new BufferedOutput();

        (new LogEntryRenderer())->render([], 'jsonl', $output);

        $this->assertStringContainsString('No matching log entries.', $output->fetch());
    }
}
