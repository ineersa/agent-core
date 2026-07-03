<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindPickerFlowInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindInteractiveRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\InteractiveCommandHostInterface;

final class TuiInteractiveCommandHost implements InteractiveCommandHostInterface
{
    public function __construct(private readonly FileRewindPickerFlowInterface $flow)
    {
    }

    public function isFileRewindPickerAvailable(): bool
    {
        return $this->flow->isWired();
    }

    public function openFileRewindPicker(FileRewindInteractiveRequestDTO $request): void
    {
        $this->flow->open($request->sessionId);
    }
}
