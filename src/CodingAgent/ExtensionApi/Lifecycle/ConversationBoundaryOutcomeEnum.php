<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Terminal conversation outcome for post-commit boundary notifications.
 *
 * Only durable terminal outcomes are emitted. Cancelling and retryable
 * failures are not boundary outcomes.
 */
enum ConversationBoundaryOutcomeEnum: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
