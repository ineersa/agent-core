<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

enum RetainedToolCompletionFailureCodeEnum: string
{
    case BodyMissing = 'body_missing';
    case BodyCorrupt = 'body_corrupt';
    case Unsupported = 'unsupported';
    case ForeignScope = 'foreign_scope';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
}
