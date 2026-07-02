<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

interface InteractiveCommandHostInterface
{
    public function openFileRewindPicker(FileRewindInteractiveRequestDTO $request): void;
}
