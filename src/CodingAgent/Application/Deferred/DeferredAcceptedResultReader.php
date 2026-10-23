<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredAcceptedResultReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;

/** Route accepted deferred bodies by native ownership, not by probing paths. */
final readonly class DeferredAcceptedResultReader implements DeferredAcceptedResultReaderInterface
{
    public function __construct(
        private CodeModeExecutionRepository $codeModeExecutions,
        private CodeModeResultArtifactStore $codeModeArtifacts,
        private ToolPayloadStoreInterface $payloads,
    ) {
    }

    public function read(string $deferredId, ToolPayloadReferenceDTO $reference): ToolCallResult
    {
        $row = $this->codeModeExecutions->invocation($reference->runId, $reference->toolCallId);
        if (null !== $row && $row['identity'] === $deferredId) {
            return $this->codeModeArtifacts->read($reference);
        }
        $result = $this->payloads->read($reference);
        if (!$result instanceof ToolCallResult) {
            throw new ToolPayloadException('Accepted deferred completion is not a tool result.');
        }

        return $result;
    }
}
