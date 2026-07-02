<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindInteractiveRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

final readonly class FileRewindCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private FileRewindService $service,
        private FileRewindTuiActionHandler $actionHandler,
    ) {
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
        $host = $this->api->interactiveCommandHost();
        if (null === $host) {
            $context->notify('File rewind picker requires interactive TUI mode.', 'warning');

            return;
        }
        $host->openFileRewindPicker(new FileRewindInteractiveRequestDTO($sessionId, $this->service, $this->actionHandler));
    }
}
