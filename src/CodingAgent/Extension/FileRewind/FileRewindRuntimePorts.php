<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindConversationRewindBindableInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewProviderInterface;

/**
 * App-layer bridge from runtime/TUI ports to extension-registered file rewind handlers.
 */
final class FileRewindRuntimePorts implements FileRewindTurnPreviewPortInterface, FileRewindTurnActionPortInterface
{
    private ?FileRewindPreviewProviderInterface $previewProvider = null;
    private ?FileRewindActionHandlerInterface $actionHandler = null;
    private ?ConversationRewindPortInterface $conversationRewind = null;

    public function bind(
        FileRewindPreviewProviderInterface $previewProvider,
        FileRewindActionHandlerInterface $actionHandler,
    ): void {
        $this->previewProvider = $previewProvider;
        $this->actionHandler = $actionHandler;
        if ($this->actionHandler instanceof FileRewindConversationRewindBindableInterface) {
            $this->actionHandler->bindConversationRewind($this->conversationRewind);
        }
    }

    public function bindConversationRewind(?ConversationRewindPortInterface $port): void
    {
        $this->conversationRewind = $port;
        if ($this->actionHandler instanceof FileRewindConversationRewindBindableInterface) {
            $this->actionHandler->bindConversationRewind($port);
        }
    }

    public function hasCheckpoint(string $sessionId, int $turnNo): bool
    {
        return $this->previewProvider?->hasCheckpointForTurn($sessionId, $turnNo) ?? false;
    }

    public function preview(string $sessionId, int $turnNo): array
    {
        if (null === $this->previewProvider) {
            return [];
        }
        $rows = [];
        foreach ($this->previewProvider->previewForTurn($sessionId, $turnNo) as $entry) {
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
