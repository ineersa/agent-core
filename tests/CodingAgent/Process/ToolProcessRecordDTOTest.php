<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Process;

use Ineersa\CodingAgent\Process\ToolProcessKindEnum;
use Ineersa\CodingAgent\Process\ToolProcessRecordDTO;
use PHPUnit\Framework\TestCase;

final class ToolProcessKindEnumTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('foreground_tool', ToolProcessKindEnum::ForegroundTool->value);
        self::assertSame('background_tool', ToolProcessKindEnum::BackgroundTool->value);
    }
}

final class ToolProcessRecordDTOTest extends TestCase
{
    public function testConstruction(): void
    {
        $startedAt = new \DateTimeImmutable('2026-05-26 12:00:00');
        $record = new ToolProcessRecordDTO(
            runId: 'run-1',
            turnNo: 2,
            toolCallId: 'call-42',
            kind: ToolProcessKindEnum::ForegroundTool,
            pid: 12345,
            processGroupId: 12345,
            commandPreview: 'bash -c "echo hello"',
            cwd: '/tmp',
            logPath: null,
            startedAt: $startedAt,
        );

        self::assertSame('run-1', $record->runId);
        self::assertSame(2, $record->turnNo);
        self::assertSame('call-42', $record->toolCallId);
        self::assertSame(ToolProcessKindEnum::ForegroundTool, $record->kind);
        self::assertSame(12345, $record->pid);
        self::assertSame(12345, $record->processGroupId);
        self::assertSame('bash -c "echo hello"', $record->commandPreview);
        self::assertSame('/tmp', $record->cwd);
        self::assertNull($record->logPath);
        self::assertSame($startedAt, $record->startedAt);
    }

    public function testDefaults(): void
    {
        $record = new ToolProcessRecordDTO(
            runId: 'run-1',
            turnNo: 1,
            toolCallId: 'call-1',
            kind: ToolProcessKindEnum::ForegroundTool,
            pid: 9999,
        );

        self::assertSame('', $record->commandPreview);
        self::assertSame('', $record->cwd);
        self::assertNull($record->processGroupId);
        self::assertNull($record->logPath);
        self::assertNull($record->startedAt);
        self::assertNull($record->updatedAt);
    }
}
