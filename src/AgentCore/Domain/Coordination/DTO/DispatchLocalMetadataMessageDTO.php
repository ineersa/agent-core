<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination\DTO;

/** Captured synchronous coordination that must commit with mailbox metadata. */
final readonly class DispatchLocalMetadataMessageDTO
{
    public function __construct(public object $message)
    {
    }
}
