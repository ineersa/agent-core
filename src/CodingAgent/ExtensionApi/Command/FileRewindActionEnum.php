<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

enum FileRewindActionEnum: string
{
    case RestoreFiles = 'restore_files';
    case RestoreFilesAndConversation = 'restore_files_and_conversation';
    case UndoLastRestore = 'undo_last_restore';
    case Cancel = 'cancel';
}
