<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Rewind\FileRewindCheckpointService;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\FileRewindCommandHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindCommandHandlerTest extends TestCase
{
    public function testRestoreInvokesCheckpointService(): void
    {
        $checkpoint = $this->createMock(FileRewindCheckpointService::class);
        $checkpoint->expects(self::once())
            ->method('restoreForTurn')
            ->with('run-1', 2);

        $emitted = [];
        $handler = new FileRewindCommandHandler($checkpoint, new NullLogger());
        $command = new RuntimeCommand(
            id: 'c1',
            type: 'file_rewind_restore',
            runId: 'run-1',
            payload: ['turn_no' => 2],
        );
        $handler(new ControllerCommandEvent($command, static function (RuntimeEvent $e) use (&$emitted): void {
            $emitted[] = $e;
        }));

        self::assertCount(1, $emitted);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $emitted[0]->type);
        self::assertSame('file_rewind_ok', $emitted[0]->payload['status'] ?? null);
    }

    public function testUndoInvokesCheckpointService(): void
    {
        $checkpoint = $this->createMock(FileRewindCheckpointService::class);
        $checkpoint->expects(self::once())->method('undoLastRestore')->with('run-2');

        $emitted = [];
        $handler = new FileRewindCommandHandler($checkpoint, new NullLogger());
        $command = new RuntimeCommand(
            id: 'c2',
            type: 'file_rewind_undo',
            runId: 'run-2',
            payload: [],
        );
        $handler(new ControllerCommandEvent($command, static function (RuntimeEvent $e) use (&$emitted): void {
            $emitted[] = $e;
        }));

        self::assertCount(1, $emitted);
        self::assertSame('file_rewind_undo', $emitted[0]->payload['command'] ?? null);
    }

    public function testRestoreFailureEmitsProtocolError(): void
    {
        $checkpoint = $this->createMock(FileRewindCheckpointService::class);
        $checkpoint->expects(self::once())->method('restoreForTurn')->willThrowException(new \RuntimeException('boom'));

        $emitted = [];
        $handler = new FileRewindCommandHandler($checkpoint, new NullLogger());
        $command = new RuntimeCommand(
            id: 'c3',
            type: 'file_rewind_restore',
            runId: 'run-3',
            payload: ['turn_no' => 1],
        );
        $handler(new ControllerCommandEvent($command, static function (RuntimeEvent $e) use (&$emitted): void {
            $emitted[] = $e;
        }));

        self::assertCount(1, $emitted);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
        self::assertStringContainsString('boom', (string) ($emitted[0]->payload['error'] ?? ''));
    }
}
