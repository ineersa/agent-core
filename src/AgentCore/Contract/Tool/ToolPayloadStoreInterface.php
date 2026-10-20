<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;

interface ToolPayloadStoreInterface
{
    public function publish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): ToolPayloadReferenceDTO;

    public function read(ToolPayloadReferenceDTO $reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation;

    public function publishCorrelation(DeferredToolCompletionCorrelation $correlation): ToolPayloadReferenceDTO;

    public function readCorrelation(ToolPayloadReferenceDTO $reference): DeferredToolCompletionCorrelation;

    public function deleteCall(string $runId, string $toolCallId): void;

    public function deleteAllForRun(string $runId): void;
}
