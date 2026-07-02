<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindPickerFlowInterface;

final class TuiFileRewindPickerFlow implements FileRewindPickerFlowInterface
{
    private ?\Closure $openCallback = null;

    public function setOpenCallback(\Closure $callback): void
    {
        $this->openCallback = $callback;
    }

    public function open(string $sessionId): void
    {
        if (null === $this->openCallback) {
            throw new \RuntimeException('File rewind picker is not wired.');
        }
        ($this->openCallback)($sessionId);
    }
}
