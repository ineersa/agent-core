<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ErrorCapture;

/**
 * Reads HATFIELD_CAPTURE_ERRORS from the process environment.
 *
 * When enabled (default), exceptions caught by infrastructure code are
 * converted into user-visible runtime/TUI failures rather than being
 * silently logged or ignored.
 *
 * When disabled (HATFIELD_CAPTURE_ERRORS=0), the capture path rethrows
 * so callers and test harnesses see the original exception loud and fast.
 *
 * int value 1 = enabled  (default — user-facing mode)
 * int value 0 = disabled (tests, deterministic crash-on-failure mode)
 */
final class RuntimeErrorCaptureConfig
{
    public readonly bool $captureErrors;

    public function __construct(
        ?string $envValue = null,
    ) {
        $value = $envValue
            ?? $_SERVER['HATFIELD_CAPTURE_ERRORS']
            ?? $_ENV['HATFIELD_CAPTURE_ERRORS']
            ?? '1';
        $this->captureErrors = '1' === $value;
    }
}
