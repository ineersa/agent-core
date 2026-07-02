<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindService;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindTuiActionHandler;

final class FileRewindRuntimePortsHolder
{
    private static ?self $instance = null;
    private ?FileRewindRuntimePorts $ports = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function bind(FileRewindService $service, FileRewindTuiActionHandler $actionHandler): void
    {
        $this->ports ??= new FileRewindRuntimePorts();
        $this->ports->bind($service, $actionHandler);
    }

    public function ports(): FileRewindRuntimePorts
    {
        return $this->ports ??= new FileRewindRuntimePorts();
    }
}
