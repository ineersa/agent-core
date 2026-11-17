<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Exception;

final class UnsupportedHostCapabilityException extends \LogicException
{
    public static function for(string $capability): self
    {
        return new self(\sprintf('The Claude Code task-workflow host does not provide Extension API capability "%s".', $capability));
    }
}
