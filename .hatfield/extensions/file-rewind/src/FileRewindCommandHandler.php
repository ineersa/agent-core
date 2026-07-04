<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;

final readonly class FileRewindCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(private FileRewindPickerController $picker)
    {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        $sessionId = $context->getSessionId();
        if ('' === $sessionId) {
            $context->notify('File rewind requires an active session.', 'error');

            return;
        }
        $this->picker->open($sessionId);
    }
}
