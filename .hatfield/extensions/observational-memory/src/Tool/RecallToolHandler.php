<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Tool\ContextualExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;

/**
 * Permanent ambient recall tool: exact/prefix one-ID lookup.
 *
 * Default scope is the current session. Pass session_id to recover a memory from an
 * explicit originating session returned by search.
 *
 * Returns TOON-encoded structured results for model correction; never logs recalled payloads.
 * Cooperative cancel/timeout maps are returned as plain structured arrays (not TOON) so
 * ToolExecutor can preserve cancelled/timed_out control flags in domain details.
 */
final class RecallToolHandler implements ContextualExtensionToolHandlerInterface
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
                'message' => 'Cancelled before recall started.',
            ];
        }
        if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
            return [
                'timed_out' => true,
                'timeout_seconds' => $context->timeoutSeconds,
                'message' => 'Timed out before recall started.',
            ];
        }

        $id = $arguments['id'] ?? null;
        if (!\is_string($id)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase hex string of 12 to 64 characters.',
            ]);
        }

        $sessionId = $arguments['session_id'] ?? null;
        if (null !== $sessionId && !\is_string($sessionId)) {
            return Toon::encode([
                'ok' => false,
                'error' => 'invalid_session_id',
                'message' => 'session_id must be a non-empty session/run id when provided.',
            ]);
        }

        $result = $this->query->recall(
            $context->runId,
            $id,
            $sessionId,
            $context->cancellationToken,
            $context->timeoutSeconds,
            $deadlineNs,
        );

        // Keep cooperative interrupt maps as arrays for ToolExecutor control-flag promotion.
        if (true === ($result['cancelled'] ?? false) || true === ($result['timed_out'] ?? false)) {
            return $result;
        }

        return Toon::encode($result);
    }
}
