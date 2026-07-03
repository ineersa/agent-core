<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindConversationRewindBindableInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionHandlerInterface;

final class FileRewindTuiActionHandler implements FileRewindActionHandlerInterface, FileRewindConversationRewindBindableInterface
{
    private ?ConversationRewindPortInterface $conversationRewind = null;

    public function __construct(private readonly FileRewindService $service)
    {
    }

    public function bindConversationRewind(?ConversationRewindPortInterface $port): void
    {
        $this->conversationRewind = $port;
    }

    public function execute(string $sessionId, int $turnNo, FileRewindActionEnum $action): void
    {
        if (FileRewindActionEnum::Cancel === $action) {
            return;
        }
        if (FileRewindActionEnum::ConversationOnly === $action) {
            $this->requireConversation()->rewindToTurn($turnNo);

            return;
        }
        if (FileRewindActionEnum::RestoreFilesAndConversation === $action) {
            $this->service->restoreForTurn($sessionId, $turnNo);
            try {
                $this->requireConversation()->rewindToTurn($turnNo);
            } catch (\Throwable $e) {
                try {
                    $this->service->executeAction($sessionId, $turnNo, FileRewindActionEnum::UndoLastRestore);
                } catch (\Throwable $undoFailure) {
                    throw new \RuntimeException('File restore succeeded but conversation rewind failed; automatic file undo also failed: '.$undoFailure->getMessage(), 0, $e);
                }

                throw new \RuntimeException('File restore was undone because conversation rewind failed: '.$e->getMessage(), 0, $e);
            }

            return;
        }
        $this->service->executeAction($sessionId, $turnNo, $action);
    }

    private function requireConversation(): ConversationRewindPortInterface
    {
        if (null === $this->conversationRewind) {
            throw new \RuntimeException('Conversation rewind is unavailable.');
        }

        return $this->conversationRewind;
    }
}
