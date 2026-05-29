<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Configuration for HATFIELD_CAPTURE_ERRORS env-driven error policy.
 *
 * When enabled (default, HATFIELD_CAPTURE_ERRORS=1), uncaught exceptions
 * at top-level boundaries are converted into user-visible runtime/TUI
 * failures rather than crashing the process.
 *
 * When disabled (HATFIELD_CAPTURE_ERRORS=0), exceptions propagate
 * normally so test/CI harnesses see hard failures.
 *
 * The bool value is supplied by Symfony DI via services.yaml
 * (%%app.capture_errors%%, resolved from env(bool:HATFIELD_CAPTURE_ERRORS)
 * with a default of true in container parameters). The object never reads
 * $_SERVER/$_ENV directly — that is the container's responsibility.
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
        bool $captureErrors = true,
    ) {
        $this->captureErrors = $captureErrors;
    }
}
