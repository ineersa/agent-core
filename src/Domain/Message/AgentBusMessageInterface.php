<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

interface AgentBusMessageInterface
{
    public function runId(): string;

    public function turnNo(): int;

    public function stepId(): string;

    public function attempt(): int;

    public function idempotencyKey(): string;
}
