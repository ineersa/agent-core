<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

enum SessionRepairRefusalReasonEnum: string
{
    case DuplicateSequences = 'duplicate_sequences';
    case MissingSequences = 'missing_sequences';
    case ActiveStreaming = 'active_streaming';
    case AmbiguousPendingWork = 'ambiguous_pending_work';
    case NoEvents = 'no_events';
    case RunStateUnavailable = 'run_state_unavailable';
    case ReplayValidationFailed = 'replay_validation_failed';
}
