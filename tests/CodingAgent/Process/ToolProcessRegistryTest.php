<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Process;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Process\ToolProcessKindEnum;
use Ineersa\CodingAgent\Process\ToolProcessRecordDTO;
use Ineersa\CodingAgent\Process\ToolProcessRegistry;
use PHPUnit\Framework\TestCase;

final class ToolProcessRegistryTest extends TestCase
{
    private Connection $connection;
    private ToolProcessRegistry $registry;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->registry = new ToolProcessRegistry($this->connection);
    }

    public function testRegisterAndForegroundForRun(): void
    {
        $record = $this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001);

        $this->registry->register($record);

        $foreground = $this->registry->foregroundForRun('run-1');
        self::assertCount(1, $foreground);
        self::assertSame('call-1', $foreground[0]->toolCallId);
        self::assertSame(1001, $foreground[0]->pid);
    }

    public function testForegroundForRunFiltersByKind(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));
        $this->registry->register($this->createRecord('run-1', 'call-2', ToolProcessKindEnum::BackgroundTool, 1002));

        $foreground = $this->registry->foregroundForRun('run-1');
        self::assertCount(1, $foreground);
        self::assertSame('call-1', $foreground[0]->toolCallId);
    }

    public function testForegroundForRunFiltersByRunId(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));
        $this->registry->register($this->createRecord('run-2', 'call-2', ToolProcessKindEnum::ForegroundTool, 1002));

        self::assertCount(1, $this->registry->foregroundForRun('run-1'));
        self::assertCount(1, $this->registry->foregroundForRun('run-2'));
    }

    public function testUnregister(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));
        self::assertCount(1, $this->registry->foregroundForRun('run-1'));

        $this->registry->unregister('run-1', 'call-1');
        self::assertCount(0, $this->registry->foregroundForRun('run-1'));
    }

    public function testUnregisterDoesNotRemoveOtherRecords(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));
        $this->registry->register($this->createRecord('run-1', 'call-2', ToolProcessKindEnum::ForegroundTool, 1002));

        $this->registry->unregister('run-1', 'call-1');

        $foreground = $this->registry->foregroundForRun('run-1');
        self::assertCount(1, $foreground);
        self::assertSame('call-2', $foreground[0]->toolCallId);
    }

    public function testBackgroundForRun(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::BackgroundTool, 1001));

        $background = $this->registry->backgroundForRun('run-1');
        self::assertCount(1, $background);
        self::assertSame('call-1', $background[0]->toolCallId);
    }

    public function testRemoveRun(): void
    {
        $this->registry->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));
        $this->registry->register($this->createRecord('run-1', 'call-2', ToolProcessKindEnum::BackgroundTool, 1002));
        $this->registry->register($this->createRecord('run-2', 'call-3', ToolProcessKindEnum::ForegroundTool, 1003));

        self::assertSame(2, $this->registry->removeRun('run-1'));

        self::assertCount(0, $this->registry->foregroundForRun('run-1'));
        self::assertCount(0, $this->registry->backgroundForRun('run-1'));
        self::assertCount(1, $this->registry->foregroundForRun('run-2'));
    }

    public function testPruneOlderThan(): void
    {
        $now = new \DateTimeImmutable('2026-05-26 12:00:00');
        $old = new \DateTimeImmutable('2026-05-25 12:00:00');

        $this->registry->register($this->createRecordWithDate('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001, $now));
        $this->registry->register($this->createRecordWithDate('run-1', 'call-2', ToolProcessKindEnum::ForegroundTool, 1002, $old));

        $pruned = $this->registry->pruneOlderThan(new \DateTimeImmutable('2026-05-26 00:00:00'));
        self::assertSame(1, $pruned);

        $foreground = $this->registry->foregroundForRun('run-1');
        self::assertCount(1, $foreground);
        self::assertSame('call-1', $foreground[0]->toolCallId);
    }

    public function testCrossProcessPersistence(): void
    {
        // With an in-memory database, the same connection sees the data.
        // This test verifies that a second registry instance (sharing the
        // same SQLite file) can read records written by the first.
        $dbPath = sys_get_temp_dir().'/hatfield-reg-test-'.bin2hex(random_bytes(6)).'.sqlite';
        try {
            $conn1 = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
            $conn2 = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);

            $registry1 = new ToolProcessRegistry($conn1);
            $registry2 = new ToolProcessRegistry($conn2);

            $registry1->register($this->createRecord('run-1', 'call-1', ToolProcessKindEnum::ForegroundTool, 1001));

            $foreground = $registry2->foregroundForRun('run-1');
            self::assertCount(1, $foreground);
            self::assertSame('call-1', $foreground[0]->toolCallId);
            self::assertSame(1001, $foreground[0]->pid);
        } finally {
            if (is_file($dbPath)) {
                @unlink($dbPath);
            }
        }
    }

    private function createRecord(
        string $runId,
        string $toolCallId,
        ToolProcessKindEnum $kind,
        int $pid,
    ): ToolProcessRecordDTO {
        return $this->createRecordWithDate($runId, $toolCallId, $kind, $pid, new \DateTimeImmutable());
    }

    private function createRecordWithDate(
        string $runId,
        string $toolCallId,
        ToolProcessKindEnum $kind,
        int $pid,
        \DateTimeImmutable $startedAt,
    ): ToolProcessRecordDTO {
        return new ToolProcessRecordDTO(
            runId: $runId,
            turnNo: 1,
            toolCallId: $toolCallId,
            kind: $kind,
            pid: $pid,
            processGroupId: null,
            commandPreview: 'test command',
            cwd: '/tmp',
            logPath: null,
            startedAt: $startedAt,
        );
    }
}
