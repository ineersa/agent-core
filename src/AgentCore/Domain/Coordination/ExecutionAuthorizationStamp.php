<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Owner-issued generation identity. Transport redelivery must preserve it. */
final readonly class ExecutionAuthorizationStamp implements StampInterface
{
    public function __construct(public string $effectId, public string $requestHash)
    {
    }
}
