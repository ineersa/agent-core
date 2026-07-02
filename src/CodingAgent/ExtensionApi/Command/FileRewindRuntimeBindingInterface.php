<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

/**
 * Optional ExtensionApi hook for extensions that expose file rewind to the TUI/runtime ports.
 */
interface FileRewindRuntimeBindingInterface
{
    public function bindFileRewindRuntime(
        FileRewindPreviewProviderInterface $previewProvider,
        FileRewindActionHandlerInterface $actionHandler,
    ): void;
}
