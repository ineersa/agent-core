<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\FileRewindInteractiveRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\InteractiveCommandHostInterface;

/**
 * Headless / non-TUI wiring: extensions see no interactive picker host.
 */
final class NullInteractiveCommandHost implements InteractiveCommandHostInterface
{
    public function isFileRewindPickerAvailable(): bool
    {
        return false;
    }

    public function openFileRewindPicker(FileRewindInteractiveRequestDTO $request): void
    {
        throw new \LogicException('File rewind picker is not available in this runtime mode.');
    }
}
