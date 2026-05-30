<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Lifecycle status of a question request.
 *
 * Tracks the progression from initial submission through user
 * resolution. Answered, Rejected, and Cancelled are terminal states.
 */
enum QuestionStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
