<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;

final readonly class FileRewindCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(private FileRewindService $service)
    {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        if (!$this->service->isEnabled()) {
            $context->notify('File rewind is disabled.', 'warning');

            return;
        }
        if (!$this->service->isOperational()) {
            $context->notify('File rewind is unavailable (git missing).', 'error');

            return;
        }
        $sessionId = $context->getSessionId();
        if ('' === $sessionId) {
            $context->notify('File rewind requires an active session.', 'error');

            return;
        }
        $picker = FileRewindPickerRegistry::get();
        if (null === $picker) {
            $context->notify('File rewind picker requires interactive TUI mode.', 'warning');

            return;
        }
        $picker->open($sessionId);
    }
}
