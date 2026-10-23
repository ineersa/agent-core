<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

/**
 * CodingAgent resolves the store that published an accepted deferred result.
 * AgentCore keeps only the immutable reference on the deferred registration.
 */
interface DeferredAcceptedResultReaderInterface
{
    public function read(string $deferredId, ToolPayloadReferenceDTO $reference): ToolCallResult;
}
