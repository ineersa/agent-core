<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindPickerFlowInterface;

final class FileRewindPickerFlowAdapter implements FileRewindPickerFlowInterface
{
    private static ?self $instance = null;
    private ?\Closure $openCallback = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

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
