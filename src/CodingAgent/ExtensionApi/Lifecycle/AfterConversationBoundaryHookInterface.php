<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Invoked after Hatfield commits a terminal conversation boundary.
 *
 * Delivery is best-effort acceleration after canonical event persistence with
 * allocated seq. Hook failures must never roll back or mutate the commit.
 * Implementations must return quickly and must not perform model work.
 */
interface AfterConversationBoundaryHookInterface
{
    public function afterConversationBoundary(ConversationBoundaryDTO $boundary): void;
}
