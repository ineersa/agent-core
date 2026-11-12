<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests\Support;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;

final class RecordingCommandContext implements CommandContextInterface
{
    public array $notifications = [];

    public function notify(string $message, string $level = 'info'): void
    {
        $this->notifications[] = ['message' => $message, 'level' => $level];
    }
}
