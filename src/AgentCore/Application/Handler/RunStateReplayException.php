<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

/**
 * Thrown when a {@see SessionRunStateReplayService} cannot rebuild a valid RunState
 * from the canonical event stream — for example, when the event history
 * has non-contiguous sequences or incompatible payload shapes.
 */
final class RunStateReplayException extends \RuntimeException
{
}
