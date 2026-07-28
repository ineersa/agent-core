<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;

/**
 * Permanent ambient recall tool: exact one-ID lookup for the current session only.
 *
 * Returns structured results for model correction; never logs recalled payloads.
 */
final class RecallToolHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(
        private readonly OmQueryService $query,
    ) {
    }

    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $id = $arguments['id'] ?? null;
        if (!\is_string($id)) {
            return [
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase 64-character hex SHA-256.',
            ];
        }

        return $this->query->recall($context->runId, $id);
    }
}
