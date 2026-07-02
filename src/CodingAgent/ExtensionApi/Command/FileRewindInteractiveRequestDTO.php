<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

final readonly class FileRewindInteractiveRequestDTO
{
    public function __construct(
        public string $sessionId,
        public FileRewindPreviewProviderInterface $previewProvider,
        public FileRewindActionHandlerInterface $actionHandler,
    ) {
    }
}
