<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\ToolProcessKindEnum;
use Ineersa\CodingAgent\Tool\ToolProcessRecordDTO;
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

    public function testToArrayAndFromArray(): void
    {
        $startedAt = new \DateTimeImmutable('2026-05-26 12:00:00');
        $record = new ToolProcessRecordDTO(
            runId: 'run-1',
            turnNo: 2,
            toolCallId: 'call-42',
            kind: ToolProcessKindEnum::BackgroundTool,
            pid: 12346,
            processGroupId: null,
            commandPreview: 'sleep 100',
            cwd: '/home/project',
            logPath: '.hatfield/tmp/bg-12346.log',
            startedAt: $startedAt,
        );

        $data = $record->toArray();
        $restored = ToolProcessRecordDTO::fromArray($data);

        self::assertSame($record->runId, $restored->runId);
        self::assertSame($record->turnNo, $restored->turnNo);
        self::assertSame($record->toolCallId, $restored->toolCallId);
        self::assertSame($record->kind, $restored->kind);
        self::assertSame($record->pid, $restored->pid);
        self::assertSame($record->processGroupId, $restored->processGroupId);
        self::assertSame($record->commandPreview, $restored->commandPreview);
        self::assertSame($record->cwd, $restored->cwd);
        self::assertSame($record->logPath, $restored->logPath);
        self::assertEquals($record->startedAt->getTimestamp(), $restored->startedAt->getTimestamp());
    }
}
