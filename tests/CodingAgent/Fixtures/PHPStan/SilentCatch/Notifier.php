<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\SilentCatch;

final class Notifier
{
    public function error(string $message): void
    {
    }

    public function setResult(FailureResult $result): void
    {
    }
}
