<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;

/**
 * Thrown when a {@see RunStateRebuilderInterface} implementation cannot rebuild a valid RunState
 * from the canonical event stream — for example, when the event history
 * has non-contiguous sequences or incompatible payload shapes.
 */
final class RunStateReplayException extends \RuntimeException
{
}
