<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use Psr\Log\LoggerInterface;

/** Promote structured retrieval failures using the public extension result-hook facility. */
final readonly class RetrievalResultHook implements ToolResultHookInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
    {
        if (!\in_array($context->toolName, ['memory_search', 'recall'], true) || !\is_string($context->details['raw_result'] ?? null)) {
            return ToolResultDecisionDTO::keep();
        }
        try {
            $payload = Toon::decode($context->details['raw_result']);
        } catch (\Throwable $error) {
            // A different result processor may have changed the representation; retain its result.
            $this->logger->warning('om.retrieval.result_unreadable', [
                'run_id' => $context->runId, 'session_id' => $context->runId,
                'component' => 'observational_memory', 'event_type' => 'om.retrieval.result_unreadable',
                'exception_class' => $error::class,
            ]);

            return ToolResultDecisionDTO::keep();
        }
        if (!\is_array($payload)) {
            return ToolResultDecisionDTO::keep();
        }

        return ToolResultDecisionDTO::replace(
            isError: $context->isError || true === ($payload['isError'] ?? false) || false === ($payload['ok'] ?? null),
            details: $context->details + ['structuredContent' => $payload],
        );
    }
}
