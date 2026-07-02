<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindService;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindTuiActionHandler;
use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;

final class FileRewindRuntimePorts implements FileRewindTurnPreviewPortInterface, FileRewindTurnActionPortInterface
{
    private ?FileRewindService $service = null;
    private ?FileRewindTuiActionHandler $actionHandler = null;
    private ?ConversationRewindPortInterface $conversationRewind = null;

    public function bind(FileRewindService $service, FileRewindTuiActionHandler $actionHandler): void
    {
        $this->service = $service;
        $this->actionHandler = $actionHandler;
    }

    public function bindConversationRewind(?ConversationRewindPortInterface $port): void
    {
        $this->conversationRewind = $port;
        $this->actionHandler?->bindConversationRewind($port);
    }

    public function hasCheckpoint(string $sessionId, int $turnNo): bool
    {
        return $this->service?->hasCheckpointForTurn($sessionId, $turnNo) ?? false;
    }

    public function preview(string $sessionId, int $turnNo): array
    {
        if (null === $this->service) {
            return [];
        }
        $rows = [];
        foreach ($this->service->previewForTurn($sessionId, $turnNo) as $entry) {
            $rows[] = [
                'path' => $entry->path,
                'status' => $entry->status,
                'added' => $entry->addedLines,
                'removed' => $entry->removedLines,
            ];
        }

        return $rows;
    }

    public function execute(string $sessionId, int $turnNo, string $action): void
    {
        if (null === $this->actionHandler) {
            throw new \RuntimeException('File rewind action handler unavailable.');
        }
        $this->actionHandler->execute($sessionId, $turnNo, FileRewindActionEnum::from($action));
    }
}
