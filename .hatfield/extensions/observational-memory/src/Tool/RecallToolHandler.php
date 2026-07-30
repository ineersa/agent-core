<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;

/**
 * Permanent ambient recall tool: exact/prefix one-ID lookup for the current session only.
 *
 * Returns TOON-encoded structured results for model correction; never logs recalled payloads.
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
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase hex string of 12 to 64 characters.',
            ]);
        }

        return Toon::encode($this->query->recall($context->runId, $id));
    }
}
