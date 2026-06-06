<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

/**
 * Backed string enum for tool question lifecycle status.
 *
 * Persisted on the ToolQuestion entity via Doctrine enumType mapping.
 * A tool question starts as pending, then transitions to either answered
 * or cancelled. Once emitted to the runtime (for controller delivery),
 * the emitted timestamp is set to prevent duplicate runtime events.
 */
enum ToolQuestionStatusEnum: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Cancelled = 'cancelled';
}
