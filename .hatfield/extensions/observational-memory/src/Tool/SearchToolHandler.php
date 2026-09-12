<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;

/**
 * Permanent ambient search tool: exact substring lookup across retained OM memories.
 *
 * Returns TOON-encoded structured results. Cooperative cancel/timeout maps stay as
 * plain arrays so ToolExecutor can preserve cancelled/timed_out control flags.
 */
final class SearchToolHandler implements ContextualExtensionToolHandlerInterface
{
    public function __construct(
        private readonly OmQueryService $query,
    ) {
    }

    public function __invoke(array $arguments, ToolInvocationContextDTO $context): mixed
    {
        $deadlineNs = null;
        if (null !== $context->timeoutSeconds && $context->timeoutSeconds > 0) {
            $deadlineNs = hrtime(true) + ($context->timeoutSeconds * 1_000_000_000);
        }

        if (null !== $context->cancellationToken && $context->cancellationToken->isCancellationRequested()) {
            return [
                'cancelled' => true,
                'message' => 'Cancelled before search started.',
            ];
        }
        if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
            return [
                'timed_out' => true,
                'timeout_seconds' => $context->timeoutSeconds,
                'message' => 'Timed out before search started.',
            ];
        }

        $query = $arguments['query'] ?? null;
        if (!\is_string($query)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_query',
                'message' => 'query must be a non-empty string.',
            ]);
        }

        $after = $arguments['after'] ?? null;
        if (null !== $after && !\is_string($after)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_after',
                'message' => 'after must be YYYY-MM-DD or YYYY-MM-DD HH:MM when provided.',
            ]);
        }

        $before = $arguments['before'] ?? null;
        if (null !== $before && !\is_string($before)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_before',
                'message' => 'before must be YYYY-MM-DD or YYYY-MM-DD HH:MM when provided.',
            ]);
        }

        $limit = $arguments['limit'] ?? null;
        if (null !== $limit && (!\is_int($limit) || $limit < 1)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_limit',
                'message' => 'limit must be a positive integer when provided.',
            ]);
        }

        $result = $this->query->search(
            $query,
            $after,
            $before,
            $limit,
            $context->cancellationToken,
            $context->timeoutSeconds,
            $deadlineNs,
        );

        if (true === ($result['cancelled'] ?? false) || true === ($result['timed_out'] ?? false)) {
            return $result;
        }

        return Toon::encode($result);
    }
}
