<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

final readonly class ModelInvocationOptions
{
    public function __construct(
        public ?CancellationTokenInterface $cancelToken = null,
    ) {
    }
}
