<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Rewind\FileRewindCheckpointService;
use Ineersa\CodingAgent\Rewind\ConversationRewindInterface;
use Ineersa\CodingAgent\Rewind\TreeNavigateToTurnOrchestrator;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\TreeNavigateToTurnHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TreeNavigateToTurnHandlerTest extends TestCase
{
    public function testRestoreFailureDoesNotRewind(): void
    {
        $checkpoint = $this->createMock(FileRewindCheckpointService::class);
        $checkpoint->expects(self::once())->method('restoreForTurn')->willThrowException(new \RuntimeException('restore failed'));
        $orchestrator = new TreeNavigateToTurnOrchestrator($checkpoint, $this->createRewindStubNeverCalled());

        $handler = new TreeNavigateToTurnHandler($orchestrator, new NullLogger());
        $emitted = [];
        $event = new ControllerCommandEvent(
            new RuntimeCommand('c1', 'tree_navigate_to_turn', 'r1', ['turn_no' => 2, 'file_choice' => 'restore_files']),
            static function ($e) use (&$emitted): void { $emitted[] = $e; },
        );
        $handler($event);

        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
    }

    public function testUndoDoesNotRewind(): void
    {
        $checkpoint = $this->createMock(FileRewindCheckpointService::class);
        $checkpoint->expects(self::once())->method('undoLastRestore')->with('r1');
        $orchestrator = new TreeNavigateToTurnOrchestrator($checkpoint, $this->createRewindStubNeverCalled());

        $handler = new TreeNavigateToTurnHandler($orchestrator, new NullLogger());
        $emitted = [];
        $event = new ControllerCommandEvent(
            new RuntimeCommand('c1', 'tree_navigate_to_turn', 'r1', ['turn_no' => 2, 'file_choice' => 'undo_file_rewind']),
            static function ($e) use (&$emitted): void { $emitted[] = $e; },
        );
        $handler($event);

        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $emitted[0]->type);
        self::assertSame('file_rewind_undo_ok', $emitted[0]->payload['status'] ?? null);
    }

    private function createRewindStubNeverCalled(): ConversationRewindInterface
    {
        return new class implements ConversationRewindInterface {
            public function rewind(string $runId, int $targetTurnNo): array
            {
                throw new \RuntimeException('rewind should not be called');
            }
        };
    }
}
