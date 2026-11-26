<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Fixtures\PHPStan\SilentCatch;

final readonly class FailureResult
{
    public function __construct(public string $message)
    {
    }

    public static function fromThrowable(\Throwable $throwable): self
    {
        return new self($throwable->getMessage());
    }
}
