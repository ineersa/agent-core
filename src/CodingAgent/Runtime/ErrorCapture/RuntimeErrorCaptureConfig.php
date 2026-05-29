<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ErrorCapture;

/**
 * Reads HATFIELD_CAPTURE_ERRORS from the process environment.
 *
 * When enabled (default, HATFIELD_CAPTURE_ERRORS=1), uncaught exceptions
 * at top-level boundaries are converted into user-visible runtime/TUI
 * failures rather than crashing the process.
 *
 * When disabled (HATFIELD_CAPTURE_ERRORS=0), exceptions propagate
 * normally so test/CI harnesses see hard failures.
 *
 * Usage: inject this config at the few top-level callback boundaries
 * (HeadlessController Revolt callbacks, CancelListener, RuntimeEventPoller).
 * Most code should NOT inject this — it should throw or return typed results,
 * and let the Symfony ConsoleEvents::ERROR subscriber or the top-level
 * callback wrapper decide capture vs crash.
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
