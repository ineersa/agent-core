<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

/**
 * User choice from /tree file-restore overlay (runtime command payload).
 */
enum TreeFileRestoreChoiceEnum: string
{
    case KeepFiles = 'keep_files';
    case RestoreFiles = 'restore_files';
    case UndoFileRewind = 'undo_file_rewind';
    case Cancel = 'cancel';

    public static function tryFromPayload(mixed $value): ?self
    {
        if (!\is_string($value)) {
            return null;
        }

        return self::tryFrom($value);
    }
}
